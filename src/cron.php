<?php
declare(strict_types=1);

/**
 * S~printer background poller (CLI).
 *
 * Runs one cycle: refresh live data for every printer, persist last-known
 * levels, then evaluate alert rules and email on state transitions.
 *
 * Started on a loop by docker-entrypoint.sh (POLL_INTERVAL_MINUTES).
 * Single-instance guaranteed via an flock lock: overlapping runs skip.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

chdir(__DIR__); // so the relative 'cache' dir matches the web context

// --- single instance lock ---------------------------------------------------
$lockFile = sys_get_temp_dir() . '/sprinter-cron.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo '[cron] ', date('Y-m-d H:i:s '), " previous run still active - skipping\n";
    exit(0);
}

require_once __DIR__ . '/SnmpEnums.php';
require_once __DIR__ . '/PrinterDiscovery.php';
// A daemon may run for hours: lift the CLI CPU-time cap for this process.
set_time_limit(0);

// Run modes:
//   --daemon  (default) continuous round-robin poller: one printer per tick,
//               oldest-first, so freshness stays uniform at any fleet size
//               and page loads always hit a warm cache.
//   --once    single full sweep (legacy/CI), bounded by POLL_BUDGET_SECONDS.
$RUN_MODE = 'daemon';
foreach (argv_tail() as $a) {
    if ($a === '--once') $RUN_MODE = 'once';
    if ($a === '--daemon') $RUN_MODE = 'daemon';
}

/** argv without the script name (PHP CLI puts it in argv[0]). */
function argv_tail(): array {
    global $argv;
    return array_slice($argv ?? [], 1);
}
require_once __DIR__ . '/PollingService.php';
require_once __DIR__ . '/SmtpClient.php';
require_once __DIR__ . '/AlertService.php';
require_once __DIR__ . '/CacheManager.php';
require_once __DIR__ . '/Database.php';

$log = static function (string $msg): void {
    echo '[cron] ', date('Y-m-d H:i:s'), ' ', $msg, "\n";
};

$t0 = microtime(true);

// --- connect ----------------------------------------------------------------
$db = new Database();
if ($db->getLastError() !== null) {
    $log('DB unavailable: ' . $db->getLastError());
    exit(1);
}

$settings = $db->getSettings();

$cache = new CacheManager('cache', 300);
$discovery = new PrinterDiscovery(
    getenv('SNMP_COMMUNITY') ?: 'public',
    400000,
    1
);

// --- shared alert config -----------------------------------------------------
function alertConfig(Database $db, array $settings): array {
    return [
        'alert_threshold' => max(1, min(100, (int)($settings['alert_threshold'] ?? 15))),
        'alert_offline' => ($settings['alert_offline'] ?? '1') === '1',
        'alert_recover' => ($settings['alert_recover'] ?? '1') === '1',
        'alert_recipients' => (string)($settings['alert_recipients'] ?? ''),
        'smtp_host' => (string)($settings['smtp_host'] ?? ''),
        'smtp_port' => (string)($settings['smtp_port'] ?? ''),
        'smtp_secure' => (string)($settings['smtp_secure'] ?? 'auto'),
        'smtp_user' => (string)($settings['smtp_user'] ?? ''),
        'smtp_pass' => (string)($settings['smtp_pass'] ?? ''),
        'smtp_from_name' => (string)($settings['smtp_from_name'] ?? 'S~printer'),
        'mail_auth_mode' => (string)($settings['mail_auth_mode'] ?? 'password'),
        'oauth_tenant' => (string)($settings['oauth_tenant'] ?? ''),
        'oauth_client_id' => (string)($settings['oauth_client_id'] ?? ''),
        'oauth_refresh_token' => (string)($settings['oauth_refresh_token'] ?? ''),
        'oauth_access_token' => (string)($settings['oauth_access_token'] ?? ''),
        'oauth_token_expires' => (int)($settings['oauth_token_expires'] ?? 0),
    ];
}

/** Evaluate + notify for ONE printer. Returns human notes (empty = silent). */
function evaluateOne(Database $db, array $cfg, array $printer, array $row): array {
    $pid = (int)$row['id'];
    $states = $db->getAlertStates();
    $prev = $states[$pid] ?? [];
    $decision = AlertService::evaluate($printer, $prev, $cfg['alert_threshold'], $cfg['alert_offline'], $cfg['alert_recover']);

    // store new states (silent updates keep notified_at history intact)
    $db->setAlertState($pid, AlertService::KIND_OFFLINE, $decision['offline']['state']);
    $db->setAlertState($pid, AlertService::KIND_LOW_INK, $decision['ink']['state']);

    if ($decision['notify']) {
        $db->markAlertNotified($pid, AlertService::KIND_OFFLINE);
        $db->markAlertNotified($pid, AlertService::KIND_LOW_INK);
        return $decision['notes'];
    }
    return [];
}

/** Send one alert email about a single printer. */
function notifyOne(Database $db, array $cfg, array $printer, array $notes): bool {
    $recipients = AlertService::parseRecipients($cfg['alert_recipients']);
    if (empty($recipients)) return false;

    $printer['alertNotes'] = $notes;
    $subject = 'S~printer: ' . $printer['name'] . ' - ' . implode('; ', $notes);
    $html = AlertService::buildEmailHtml([$printer], time());
    $text = AlertService::buildEmailText([$printer], time());

    $send = AlertService::sendEmail($db, $cfg, $subject, $html, $text);
    if ($send['ok']) {
        $db->setSetting('alerts_last_sent', date('Y-m-d H:i:s'));
        $db->setSetting('alerts_last_error', '');
        return true;
    }
    $db->setSetting('alerts_last_error', date('Y-m-d H:i:s') . ' ' . $send['error']);
    return false;
}

// =============================================================================
// MODE: --once  (single bounded full sweep - legacy/CI)
// =============================================================================
if ($RUN_MODE === 'once') {
    $budget = (float)(getenv('POLL_BUDGET_SECONDS') ?: 90);

    $dbPrinters = $db->getPrinters();
    $result = PollingService::gather($dbPrinters, $discovery, $db, $cache, true, $budget);
    $printers = $result['printers'];

    $online = count(array_filter($printers, static fn($p) => $p['status'] === 'online'));
    $log(sprintf(
        'once: polled %d printer(s): %d online, %d offline in %.1fs',
        count($printers), $online, count($printers) - $online, microtime(true) - $t0
    ));

    if (($settings['alerts_enabled'] ?? '0') === '1') {
        $cfg = alertConfig($db, $settings);
        $recipients = AlertService::parseRecipients($cfg['alert_recipients']);
        if (!empty($recipients)) {
            $states = $db->getAlertStates();
            $emailPrinters = AlertService::buildEmailPrinters($printers, $dbPrinters);
            $notified = 0;
            foreach ($dbPrinters as $row) {
                $printer = $printers[$row['ip']] ?? null;
                if ($printer === null) continue;
                $decision = AlertService::evaluate(
                    $printer,
                    $states[(int)$row['id']] ?? [],
                    $cfg['alert_threshold'], $cfg['alert_offline'], $cfg['alert_recover']
                );
                $db->setAlertState((int)$row['id'], AlertService::KIND_OFFLINE, $decision['offline']['state']);
                $db->setAlertState((int)$row['id'], AlertService::KIND_LOW_INK, $decision['ink']['state']);
                if ($decision['notify']) {
                    $notified++;
                    foreach ($emailPrinters as &$ep) {
                        if ($ep['ip'] === $row['ip']) { $ep['alertNotes'] = $decision['notes']; break; }
                    }
                    unset($ep);
                    $log('alert: ' . $row['ip'] . ' -> ' . implode('; ', $decision['notes']));
                }
            }
            if ($notified > 0) {
                $subject = "S~printer: $notified alert(s) need attention";
                $send = AlertService::sendEmail(
                    $db, $cfg, $subject,
                    AlertService::buildEmailHtml($emailPrinters, time()),
                    AlertService::buildEmailText($emailPrinters, time())
                );
                $log($send['ok'] ? 'alert email sent' : 'alert email FAILED: ' . $send['error']);
            }
        }
    }
    exit(0);
}

// =============================================================================
// MODE: --daemon  (default) - continuous round-robin
// =============================================================================
// Poll ONE printer per tick in rotating order. Freshness at fleet size N is
// therefore uniform: a full sweep takes N x per-printer-time, spread evenly,
// instead of clumping into budget-capped bursts. Pages almost always hit a
// warm cache.
$cfg = alertConfig($db, $settings);
$recipients = AlertService::parseRecipients($cfg['alert_recipients']);
$minFresh = 60;   // never re-poll the same printer more than once a minute
$cache->setTtl(max(300, count($db->getPrinters()) * 2 + 60)); // matches the page reader

$log('daemon started (round-robin, min-fresh ' . $minFresh . 's)');

$cursor = 0;
while (true) {
    $dbPrinters = $db->getPrinters();
    $n = count($dbPrinters);
    if ($n === 0) { sleep(5); continue; }

    $row = $dbPrinters[$cursor % $n];
    $cursor++;

    // skip if a fresh cache entry exists (a page load may have just polled it)
    $live = $cache->get('live_' . $row['ip']);
    $fresh = is_array($live) && isset($live['fetched_at'])
        && (time() - (int)$live['fetched_at']) < $minFresh;

    if (!$fresh) {
        $tick = microtime(true);
        $printer = PollingService::pollPrinter($row, $discovery, $db, $cache, true);
        $log(sprintf('polled %s (%s) in %.2fs', $row['ip'], $printer['status'], microtime(true) - $tick));

        if (($settings['alerts_enabled'] ?? '0') === '1') {
            $notes = evaluateOne($db, $cfg, [
                'status' => $printer['status'],
                'levels' => $printer['levels'],
                'info' => ['last_levels' => $row['last_levels'] ?? null]
            ], $row);
            if (!empty($notes)) {
                $printerFull = [
                    'name' => $row['name'] ?: 'Unknown Printer',
                    'ip' => $row['ip'],
                    'status' => $printer['status'],
                    'levels' => $printer['levels'],
                    'alertNotes' => $notes
                ];
                $sent = notifyOne($db, $cfg, $printerFull, $notes);
                $log('alert: ' . $row['ip'] . ' -> ' . implode('; ', $notes) . ($sent ? ' (email sent)' : ' (email FAILED)'));
            }
        }
        usleep(200000); // small breather between printers
    } else {
        usleep(1000000); // everything fresh: idle
    }
}
