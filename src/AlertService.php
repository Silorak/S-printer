<?php
declare(strict_types=1);

/**
 * AlertService
 *
 * Alert decision engine + "artistic" HTML email builder/sender.
 *
 * Design notes (the researched bits):
 * - Deduplication is STATE-CHANGE based: the first enabled run records a
 *   silent baseline (no alert storm after deploying), then emails are sent
 *   only on transitions: a cartridge crossing the low threshold, a printer
 *   going offline, or coming back.
 * - Low-ink evaluation uses live levels for online printers and the stored
 *   "last known levels" for offline ones (best available data, dedup keeps
 *   it quiet).
 * - The email uses TABLE layout with inline styles + bgcolor: Outlook's
 *   desktop client renders with the Word engine, which ignores flex/grid
 *   but renders nested tables faithfully. The ink bars are therefore
 *   two-cell tables (fill cell + remainder cell).
 */
class AlertService {
    public const KIND_OFFLINE = 'offline';
    public const KIND_LOW_INK = 'low_ink';

    private const MAX_RECIPIENTS = 25;

    // ================================================================
    // PURE DECISION LOGIC (unit-testable, no I/O)
    // ================================================================

    /**
     * Which cartridge colors are currently at/below the threshold.
     * Negative SNMP sentinels (-1/-2/-3, "unknown") never trigger alerts
     * - no false alarms from devices that cannot measure.
     *
     * @return array Sorted list of low color names
     */
    public static function lowColors(array $levels, int $threshold): array {
        $low = [];
        foreach ($levels as $color => $lvl) {
            if (!is_array($lvl)) continue;
            $pct = (float)($lvl['percentage'] ?? -1);
            if ($pct < 0) continue;
            if ($pct <= $threshold) $low[] = (string)$color;
        }
        sort($low);
        return $low;
    }

    /**
     * Offline transition decision.
     *
     * @param string|null $prevState null = first ever observation (baseline: record, never alert)
     */
    public static function decideOffline(?string $prevState, bool $isOnline, bool $onOffline, bool $onRecover): array {
        $newState = $isOnline ? 'online' : 'offline';
        if ($prevState === null || $prevState === $newState) {
            return ['state' => $newState, 'notify' => false, 'reason' => null];
        }
        if (!$isOnline && $onOffline) {
            return ['state' => $newState, 'notify' => true, 'reason' => 'went offline'];
        }
        if ($isOnline && $onRecover) {
            return ['state' => $newState, 'notify' => true, 'reason' => 'is back online'];
        }
        return ['state' => $newState, 'notify' => false, 'reason' => null];
    }

    /**
     * Low-ink transition decision.
     *
     * @param string|null $prevStateJson JSON array of previously-low colors (null = baseline)
     * @return array{state:string, notify:bool, added:array, cleared:array}
     */
    public static function decideLowInk(?string $prevStateJson, array $lowColors): array {
        $new = array_values($lowColors);
        $prev = $prevStateJson === null ? null : (json_decode($prevStateJson, true) ?: []);
        $added = is_array($prev) ? array_values(array_diff($new, $prev)) : [];
        $cleared = is_array($prev) ? array_values(array_diff($prev, $new)) : [];

        // Baseline run (no previous state): record silently.
        // Notify only when a NEW color enters the low set.
        $notify = is_array($prev) && !empty($added);
        return ['state' => json_encode($new), 'notify' => $notify, 'added' => $added, 'cleared' => $cleared];
    }

    /**
     * Parse a recipients field ("a@b.lt, c@d.lt; junk") into valid, deduped addresses.
     */
    public static function parseRecipients(?string $raw): array {
        if ($raw === null || trim($raw) === '') return [];
        $parts = preg_split('/[,;\s]+/', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL) && !in_array($p, $out, true)) {
                $out[] = $p;
            }
            if (count($out) >= self::MAX_RECIPIENTS) break;
        }
        return $out;
    }

    // ================================================================
    // EMAIL BUILDING (table layout, inline styles - Outlook safe)
    // ================================================================

    private static function barColor(float $pct): string {
        if ($pct >= 50) return '#2e7d32'; // green
        if ($pct >= 25) return '#f9a825'; // amber
        if ($pct >= 10) return '#ef6c00'; // orange
        return '#c62828';                 // red
    }

    /**
     * One ink bar as a nested table (renders in Outlook, Gmail, Apple Mail).
     */
    private static function barHtml(string $label, float $pct): string {
        $pct = max(0.0, min(100.0, $pct));
        $fillW = max(1, (int)round($pct));
        $restW = 100 - $fillW;
        $color = self::barColor($pct);
        $labelSafe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        // "0%" carries no information - the empty state is the news
        $pctText = $pct <= 0 ? 'EMPTY' : (($pct == (int)$pct ? (int)$pct : round($pct, 1)) . '%');

        return '<tr>'
            . '<td style="padding:4px 8px 4px 0;font:12px Arial,sans-serif;color:#555;white-space:nowrap;width:90px;">' . $labelSafe . '</td>'
            . '<td>'
            .   '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
            .   '<tr>'
            .   '<td width="' . $fillW . '%" bgcolor="' . $color . '" style="height:14px;border-radius:6px 0 0 6px;">&nbsp;</td>'
            .   '<td width="' . $restW . '%" bgcolor="#e8e6df" style="height:14px;border-radius:0 6px 6px 0;">&nbsp;</td>'
            .   '</tr></table>'
            . '</td>'
            . '<td align="right" style="padding:4px 0 4px 8px;font:bold 12px Arial,sans-serif;color:#333;white-space:nowrap;width:44px;">' . $pctText . '</td>'
            . '</tr>';
    }

    /**
     * One printer card (name, status badge, bars).
     */
    private static function printerCardHtml(string $name, string $ip, string $status, array $levels, bool $highlight): string {
        $nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $ipSafe = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');

        if ($status === 'offline') {
            $badge = '<span style="background:#c62828;color:#fff;font:bold 11px Arial,sans-serif;padding:2px 10px;border-radius:10px;">OFFLINE</span>';
        } elseif ($highlight) {
            $badge = '<span style="background:#ef6c00;color:#fff;font:bold 11px Arial,sans-serif;padding:2px 10px;border-radius:10px;">LOW SUPPLY</span>';
        } else {
            $badge = '<span style="background:#2e7d32;color:#fff;font:bold 11px Arial,sans-serif;padding:2px 10px;border-radius:10px;">OK</span>';
        }

        // Show bars whenever we have data: live levels for online printers,
        // stored "last known levels" for offline ones (the OFFLINE badge
        // already tells the reader the data may be stale).
        $bars = '';
        if (!empty($levels)) {
            $rows = '';
            foreach ($levels as $color => $lvl) {
                if (!is_array($lvl)) continue;
                $label = ucfirst((string)($lvl['description'] ?? $color));
                $rows .= self::barHtml($label, (float)($lvl['percentage'] ?? 0));
            }
            if ($rows !== '') {
                $bars = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:6px;">' . $rows . '</table>';
                if ($status === 'offline') {
                    $bars .= '<div style="font:italic 10px Arial,sans-serif;color:#888;margin-top:4px;">Last known levels.</div>';
                }
            }
        } elseif ($status === 'offline') {
            $bars = '<div style="font:italic 12px Arial,sans-serif;color:#888;margin-top:6px;">No supply data collected yet.</div>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#f6f3e8;border:1px solid #e0dccb;border-radius:8px;margin-bottom:14px;">'
            . '<tr><td style="padding:12px 14px 4px 14px;">'
            .   '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            .   '<td style="font:bold 14px Arial,sans-serif;color:#2d353b;">' . $nameSafe . '</td>'
            .   '<td align="right">' . $badge . '</td>'
            .   '</tr></table>'
            .   '<div style="font:11px Arial,sans-serif;color:#999;">' . $ipSafe . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:2px 14px 12px 14px;">' . $bars . '</td></tr>'
            . '</table>';
    }

    /**
     * Full artistic HTML email: banner, "needs attention" cards first, then the fleet.
     *
     * @param array $printers List of ['name','ip','status','levels','alertNotes'=>[]]
     */
    public static function buildEmailHtml(array $printers, int $ts): string {
        $date = date('d M Y H:i', $ts);
        // Preheader: invisible preview text for notification panes
        $preheader = '<div style="display:none;max-height:0;overflow:hidden;">'
            . 'Printer status report - ' . count($printers) . ' printers monitored.'
            . '</div>';
        // Outer full-width + fixed 600px centered column (proper email ergonomics)
        $html = $preheader
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#5a5a5a" style="background:#5a5a5a;"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;"><tr><td>'
            . '<div style="background:#2d353b;border-radius:10px 10px 0 0;padding:18px 20px;">'
            . '<span style="font:bold 20px Arial,sans-serif;color:#7fbbb3;">&#128424; S~printer</span>'
            . '<span style="font:12px Arial,sans-serif;color:#d3c6aa;"> &nbsp;status report &middot; ' . $date . ' UTC</span>'
            . '</div>'
            . '<div style="background:#ffffff;padding:16px 20px;border:1px solid #e0dccb;border-top:none;border-radius:0 0 10px 10px;">';

        $attention = array_filter($printers, fn($p) => !empty($p['alertNotes']));
        $others = array_filter($printers, fn($p) => empty($p['alertNotes']));

        if (!empty($attention)) {
            $html .= '<div style="font:bold 13px Arial,sans-serif;color:#c62828;text-transform:uppercase;letter-spacing:1px;margin:4px 0 10px;">Needs attention</div>';
            foreach ($attention as $p) {
                $html .= self::printerCardHtml((string)$p['name'], (string)$p['ip'], (string)$p['status'], (array)($p['levels'] ?? []), true);
                foreach ((array)($p['alertNotes'] ?? []) as $note) {
                    $html .= '<div style="font:12px Arial,sans-serif;color:#c62828;margin:-8px 0 12px 4px;">&bull; '
                        . htmlspecialchars((string)$note, ENT_QUOTES, 'UTF-8') . '</div>';
                }
            }
        }

        if (!empty($others)) {
            $html .= '<div style="font:bold 13px Arial,sans-serif;color:#2e7d32;text-transform:uppercase;letter-spacing:1px;margin:14px 0 10px;">All good</div>';
            foreach ($others as $p) {
                $html .= self::printerCardHtml((string)$p['name'], (string)$p['ip'], (string)$p['status'], (array)($p['levels'] ?? []), false);
            }
        }

        $html .= '<div style="font:11px Arial,sans-serif;color:#aaa;margin-top:8px;">'
            . 'Sent by your local S~printer monitor. Thresholds and recipients are configurable in Settings &rarr; Alerts.'
            . '</div></div>'
            . '</td></tr></table></td></tr></table>';

        return $html;
    }

    /**
     * Plain-text alternative for text-only clients.
     */
    public static function buildEmailText(array $printers, int $ts): string {
        $out = ["S~printer status report - " . date('d M Y H:i', $ts) . " UTC", str_repeat('-', 40)];
        foreach ($printers as $p) {
            $out[] = ($p['status'] === 'offline' ? '[OFFLINE] ' : '') . $p['name'] . ' (' . $p['ip'] . ')';
            foreach ((array)($p['levels'] ?? []) as $color => $lvl) {
                if (!is_array($lvl)) continue;
                $out[] = '  ' . ucfirst((string)($lvl['description'] ?? $color)) . ': ' . (float)($lvl['percentage'] ?? 0) . '%';
            }
            foreach ((array)($p['alertNotes'] ?? []) as $note) {
                $out[] = '  ! ' . $note;
            }
        }
        return implode("\n", $out);
    }

    // ================================================================
    // OAUTH2 (Microsoft 365) + SENDING
    // ================================================================

    /**
     * Device-code flow start (public client - no secret, no redirect URI).
     * Returns [verification_uri, user_code, message, device_code, expires_in] or error.
     */
    public static function oauthStart(string $clientId, string $tenant): array {
        $tenant = $tenant !== '' ? $tenant : 'organizations';
        $resp = self::httpPost("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/devicecode", [
            'client_id' => $clientId,
            'scope' => 'https://outlook.office.com/SMTP.Send offline_access openid profile',
        ]);
        if ($resp === null) return ['error' => 'Could not reach login.microsoftonline.com'];
        $data = json_decode($resp, true);
        if (!is_array($data) || isset($data['error'])) {
            return ['error' => $data['error_description'] ?? 'Device code request rejected - check Client ID'];
        }
        return [
            'device_code' => (string)$data['device_code'],
            'verification_uri' => (string)$data['verification_uri'],
            'user_code' => (string)$data['user_code'],
            'message' => (string)($data['message'] ?? ''),
            'expires_in' => (int)($data['expires_in'] ?? 900),
            'interval' => (int)($data['interval'] ?? 5)
        ];
    }

    /**
     * Device-code poll. Returns ['status' => 'pending'|'ok'|'error'|'expired', ...].
     * On success stores refresh + access tokens in settings (tokens NEVER
     * leave the server).
     */
    public static function oauthPoll(Database $db, string $clientId, string $tenant, string $deviceCode): array {
        $tenant = $tenant !== '' ? $tenant : 'organizations';
        $resp = self::httpPost("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
            'client_id' => $clientId,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $deviceCode,
            'scope' => 'https://outlook.office.com/SMTP.Send offline_access openid profile',
        ]);
        if ($resp === null) return ['status' => 'pending', 'message' => 'token endpoint unreachable'];
        $data = json_decode($resp, true);
        if (!is_array($data)) return ['status' => 'pending', 'message' => 'bad token response'];

        if (isset($data['error'])) {
            $err = (string)$data['error'];
            if ($err === 'authorization_pending') return ['status' => 'pending'];
            if ($err === 'slow_down') return ['status' => 'pending', 'message' => 'slowing down'];
            if ($err === 'authorization_expired') return ['status' => 'expired'];
            return ['status' => 'error', 'message' => (string)($data['error_description'] ?? $err)];
        }

        // success: persist tokens (refresh token rotates on every use!)
        if (!empty($data['refresh_token'])) {
            $db->setSetting('oauth_refresh_token', (string)$data['refresh_token']);
        }
        $db->setSetting('oauth_access_token', (string)($data['access_token'] ?? ''));
        $db->setSetting('oauth_token_expires', (string)(time() + (int)($data['expires_in'] ?? 3600)));
        return ['status' => 'ok'];
    }

    /**
     * Fresh access token for SMTP.Send: returns cached one while valid,
     * otherwise refreshes via the stored refresh token.
     */
    public static function ensureAccessToken(Database $db, array $cfg): string {
        $expires = (int)($cfg['oauth_token_expires'] ?? 0);
        $cached = (string)($cfg['oauth_access_token'] ?? '');
        if ($cached !== '' && $expires - 120 > time()) {
            return $cached;
        }
        if (empty($cfg['oauth_refresh_token'])) {
            throw new RuntimeException('Not connected to Microsoft 365 - use "Connect" first');
        }
        $tenant = (string)($cfg['oauth_tenant'] ?? '');
        $tenant = $tenant !== '' ? $tenant : 'organizations';
        $post = [
            'client_id' => (string)($cfg['oauth_client_id'] ?? ''),
            'grant_type' => 'refresh_token',
            'refresh_token' => $cfg['oauth_refresh_token'],
            'scope' => 'https://outlook.office.com/SMTP.Send offline_access openid profile',
        ];
        if (!empty($cfg['oauth_client_secret'])) {
            $post['client_secret'] = (string)$cfg['oauth_client_secret'];
        }
        $resp = self::httpPost("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", $post);
        if ($resp === null) throw new RuntimeException('Token refresh failed: could not reach login.microsoftonline.com');
        $data = json_decode($resp, true);
        if (!is_array($data) || empty($data['access_token'])) {
            $err = (string)($data['error_description'] ?? $data['error'] ?? 'unknown');
            throw new RuntimeException('Token refresh failed: ' . substr($err, 0, 160));
        }
        // refresh tokens ROTATE: persist the new one
        if (!empty($data['refresh_token'])) {
            $db->setSetting('oauth_refresh_token', (string)$data['refresh_token']);
        }
        $db->setSetting('oauth_access_token', (string)$data['access_token']);
        $db->setSetting('oauth_token_expires', (string)(time() + (int)($data['expires_in'] ?? 3600)));
        return (string)$data['access_token'];
    }

    private static function httpPost(string $url, array $fields): ?string {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($fields),
            'timeout' => 15,
            'ignore_errors' => true
        ]]);
        return @file_get_contents($url, false, $ctx) ?: null;
    }

    /**
     * Send via SmtpClient. $cfg carries either smtp_pass (basic) or OAuth2
     * fields (oauth_*); $db is needed to persist refreshed tokens.
     */
    public static function sendEmail(Database $db, array $cfg, string $subject, string $html, string $text): array {
        $recipients = self::parseRecipients($cfg['alert_recipients'] ?? '');
        if (empty($recipients)) return ['ok' => false, 'error' => 'No valid recipients configured'];
        if (empty($cfg['smtp_host'])) return ['ok' => false, 'error' => 'SMTP host not configured'];
        if (empty($cfg['smtp_user'])) return ['ok' => false, 'error' => 'SMTP username not configured'];

        $authMode = $cfg['mail_auth_mode'] ?? 'password';
        $oauthToken = null;
        $password = null;
        try {
            if ($authMode === 'oauth2') {
                $oauthToken = self::ensureAccessToken($db, $cfg);
            } else {
                $password = (string)($cfg['smtp_pass'] ?? '');
                if ($password === '') return ['ok' => false, 'error' => 'SMTP password not configured'];
            }

            $client = new SmtpClient(
                (string)$cfg['smtp_host'],
                (int)($cfg['smtp_port'] ?: 587),
                (string)($cfg['smtp_secure'] ?? 'auto')
            );
            $ok = $client->send(
                (string)$cfg['smtp_user'],
                $password,
                $oauthToken,
                (string)$cfg['smtp_user'],
                trim((string)($cfg['smtp_from_name'] ?? '')) ?: 'S~printer',
                $recipients,
                $subject,
                $html,
                $text
            );
            return $ok ? ['ok' => true, 'error' => null]
                       : ['ok' => false, 'error' => $client->lastError ?: 'SMTP send failed'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ================================================================
    // ORCHESTRATION (used by cron.php and send_test_email)
    // ================================================================

    /**
     * Build the printer list for the email from gathered live data,
     * enriching offline printers with their stored last-known levels.
     *
     * @param array $printersByKey Output of PollingService::gather (keyed by IP)
     * @param array $dbPrinters Raw DB rows (for last_levels)
     */
    public static function buildEmailPrinters(array $printersByKey, array $dbPrinters): array {
        $lastByIp = [];
        foreach ($dbPrinters as $row) {
            $lastByIp[$row['ip']] = $row;
        }
        $out = [];
        foreach ($printersByKey as $ip => $p) {
            $levels = (array)($p['levels'] ?? []);
            if ($p['status'] !== 'online' && empty($levels)) {
                $stored = $lastByIp[$ip]['last_levels'] ?? null;
                if (is_string($stored) && $stored !== '') {
                    $decoded = json_decode($stored, true);
                    if (is_array($decoded)) $levels = $decoded;
                }
            }
            $out[] = [
                'name' => (string)($p['info']['full_name'] ?? $ip),
                'ip' => (string)$ip,
                'status' => (string)$p['status'],
                'levels' => $levels,
                'alertNotes' => []
            ];
        }
        return $out;
    }

    /**
     * Evaluate alerts for one printer against its stored state.
     * Returns per-kind decisions + human notes for the email.
     */
    public static function evaluate(array $printer, ?array $prevRow, int $threshold, bool $onOffline, bool $onRecover): array {
        $isOnline = ($printer['status'] ?? 'offline') === 'online';
        // $prevRow values are the raw state strings from getAlertStates()
        $prevOffline = $prevRow[self::KIND_OFFLINE] ?? null;
        $prevInk = $prevRow[self::KIND_LOW_INK] ?? null;

        $offline = self::decideOffline($prevOffline, $isOnline, $onOffline, $onRecover);

        $levels = (array)($printer['levels'] ?? []);
        if (empty($levels)) {
            // Offline printers: fall back to last known levels (best data available)
            $stored = $printer['info']['last_levels'] ?? null;
            if (is_string($stored) && $stored !== '') {
                $dec = json_decode($stored, true);
                if (is_array($dec)) $levels = $dec;
            }
        }
        $low = self::lowColors($levels, $threshold);
        $ink = self::decideLowInk($prevInk, $low);

        $notes = [];
        if ($offline['notify']) $notes[] = 'Printer ' . $offline['reason'];
        if ($ink['notify']) $notes[] = 'Low supply: ' . implode(', ', array_map('ucfirst', $ink['added']));

        return [
            'offline' => $offline,
            'ink' => $ink,
            'notify' => $offline['notify'] || $ink['notify'],
            'notes' => $notes
        ];
    }
}
