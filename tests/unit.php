<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(function($no,$str){ throw new ErrorException($str); });

require __DIR__ . '/../src/SnmpEnums.php';
require __DIR__ . '/../src/PrinterDiscovery.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/CacheManager.php';

$pass = 0; $fail = 0;
function check(string $name, bool $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $name\n"; }
    else { $fail++; echo "FAIL  $name\n"; }
}

$m = new ReflectionMethod('PrinterDiscovery', 'determineColor');
$m->setAccessible(true);
$d = new PrinterDiscovery();
$colorOf = function (string $s) use ($m, $d) { return $m->invoke($d, $s); };

// THE BUG: "Ink" must not trigger black via the k-boundary
check('Cyan Ink Cartridge -> cyan', $colorOf('Cyan Ink Cartridge') === 'cyan');
check('Magenta Ink -> magenta', $colorOf('LC203 Magenta Ink') === 'magenta');
check('Yellow Ink -> yellow', $colorOf('Yellow Ink Cartridge') === 'yellow');
check('Black Ink Cartridge -> black', $colorOf('Black Ink Cartridge') === 'black');
check('Bare "Ink" -> other', $colorOf('Ink Cartridge') === 'other');
check('Toner Cartridge K -> black', $colorOf('Toner Cartridge K') === 'black');
check('Noir Toner -> black', $colorOf('Toner Noir') === 'black');
check('Gelb -> yellow', $colorOf('Gelb Cartridge') === 'yellow');
check('Cyan -> cyan', $colorOf('Cyan Toner') === 'cyan');
check('plain toner -> black', $colorOf('Toner Cartridge') === 'black');

// ---- Database validation helpers ----
check('isValidIp: 192.168.1.5', Database::isValidIp('192.168.1.5'));
check('isValidIp: ::1', Database::isValidIp('::1'));
check('isValidIp: rejects <script>', !Database::isValidIp('"><svg onload=alert(1)>'));
check('isValidIp: rejects 999.1.1.1', !Database::isValidIp('999.1.1.1'));
check('isValidIp: rejects array', !Database::isValidIp(['a']));
check('isValidIp: rejects null', !Database::isValidIp(null));
check('sanitizeUrl: https ok', Database::sanitizeUrl('https://shop.example.com/toner') === 'https://shop.example.com/toner');
check('sanitizeUrl: http ok', Database::sanitizeUrl('http://x.test/a%20b') === 'http://x.test/a%20b');
check('sanitizeUrl: raw space rejected (button hidden, safe)', Database::sanitizeUrl('http://x.test/a b') === null);
check('sanitizeUrl: javascript: rejected', Database::sanitizeUrl('javascript:alert(1)') === null);
check('sanitizeUrl: data: rejected', Database::sanitizeUrl('data:text/html,x') === null);
check('sanitizeUrl: junk rejected', Database::sanitizeUrl('not a url') === null);
check('sanitizeUrl: empty -> null', Database::sanitizeUrl('') === null);
check('normalizeLevelsJson: array', Database::normalizeLevelsJson(['black' => ['percentage' => 50]]) !== null);
check('normalizeLevelsJson: scalar -> null', Database::normalizeLevelsJson('x') === null);

// ---- CacheManager ----
$dir = sys_get_temp_dir() . '/sprinter_cache_test_' . getmypid();
@mkdir($dir);
$c = new CacheManager($dir, 2);
check('cache dir created', is_dir($dir));
check('.htaccess written', file_exists("$dir/.htaccess") && strpos((string)file_get_contents("$dir/.htaccess"), 'Require all denied') !== false);
check('index.html written', file_exists("$dir/index.html"));

$c->set('k1', ['status' => 'online', 'levels' => []]);
check('set/get roundtrip', $c->get('k1') === ['status' => 'online', 'levels' => []]);
check('compact json (no pretty print)', strpos((string)file_get_contents("$dir/" . md5('k1') . '.json'), "\n  ") === false);
check('empty array is fresh (not null)', $c->get('k1') !== null);

$leftovers = glob("$dir/*.tmp");
check('no leftover tmp files', $leftovers === false || count($leftovers) === 0);

// expiry
$c->set('k2', ['x' => 1]);
touch("$dir/" . md5('k2') . '.json', time() - 10);
check('expired -> null', $c->get('k2') === null);

// corrupted file -> null (not crash)
file_put_contents("$dir/" . md5('k3') . '.json', '{broken');
check('corrupt -> null', $c->get('k3') === null);

// delete + clear
$c->set('k4', 1);
check('delete', $c->delete('k4') && $c->get('k4') === null);
$c->set('k5', 1); $c->set('k6', 2);
check('clear', $c->clear() && $c->get('k5') === null && $c->get('k6') === null);


// ---- AlertService (pure logic) ----
require __DIR__ . '/../src/AlertService.php';

$levelsFull = [
    'black'   => ['percentage' => 72],
    'cyan'    => ['percentage' => 45],
    'magenta' => ['percentage' => 10],
    'yellow'  => ['percentage' => 0, 'status' => 'empty_or_not_genuine'],
];
check('lowColors: threshold 15 -> magenta+yellow',
      AlertService::lowColors($levelsFull, 15) === ['magenta', 'yellow']);
check('lowColors: threshold 50 -> cyan+magenta+yellow',
      AlertService::lowColors($levelsFull, 50) === ['cyan', 'magenta', 'yellow']);
check('lowColors: threshold 0 -> yellow only',
      AlertService::lowColors($levelsFull, 0) === ['yellow']);
check('lowColors: sentinel negatives excluded',
      AlertService::lowColors(['x' => ['percentage' => -3]], 15) === []);
check('lowColors: sorted output',
      AlertService::lowColors(['z' => ['percentage' => 5], 'a' => ['percentage' => 2]], 15) === ['a', 'z']);

// offline transitions
$d1 = AlertService::decideOffline(null, false, true, true);
check('offline: baseline records silently', $d1['state'] === 'offline' && $d1['notify'] === false);
$d2 = AlertService::decideOffline('online', false, true, true);
check('offline: online->offline notifies', $d2['notify'] === true && $d2['reason'] === 'went offline');
$d3 = AlertService::decideOffline('online', false, false, true);
check('offline: disabled -> silent', $d3['notify'] === false);
$d4 = AlertService::decideOffline('offline', true, true, true);
check('offline: recovery notifies', $d4['notify'] === true && $d4['reason'] === 'is back online');
$d5 = AlertService::decideOffline('offline', false, true, true);
check('offline: unchanged -> silent', $d5['notify'] === false);

// low-ink transitions
$i1 = AlertService::decideLowInk(null, ['cyan']);
check('lowInk: baseline records silently', $i1['notify'] === false && json_decode($i1['state'], true) === ['cyan']);
$i2 = AlertService::decideLowInk('["cyan"]', ['cyan', 'magenta']);
check('lowInk: addition notifies', $i2['notify'] === true && $i2['added'] === ['magenta']);
$i3 = AlertService::decideLowInk('["cyan","magenta"]', ['cyan']);
check('lowInk: cleared = silent (cartridge replaced)', $i3['notify'] === false && $i3['cleared'] === ['magenta']);
$i4 = AlertService::decideLowInk('["cyan"]', ['cyan']);
check('lowInk: unchanged = silent', $i4['notify'] === false);

// recipients
check('recipients: mixed separators + junk',
      AlertService::parseRecipients('a@b.lt; c@d.lt, junk ,, e@f.lt') === ['a@b.lt', 'c@d.lt', 'e@f.lt']);
check('recipients: dedup + invalid dropped',
      AlertService::parseRecipients('a@b.lt, a@b.lt, nope') === ['a@b.lt']);
check('recipients: empty', AlertService::parseRecipients('') === []);

// email builder: escaping + bars + sections
$printers = [
    ['name' => '<script>x</script> HP', 'ip' => '192.0.2.1', 'status' => 'online',
     'levels' => ['black' => ['percentage' => 72, 'description' => 'black']], 'alertNotes' => ['Low supply: black']],
    ['name' => 'Offline One', 'ip' => '192.0.2.2', 'status' => 'offline', 'levels' => [], 'alertNotes' => ['Printer went offline']],
    ['name' => 'Healthy', 'ip' => '192.0.2.3', 'status' => 'online',
     'levels' => ['cyan' => ['percentage' => 88, 'description' => 'cyan']], 'alertNotes' => []],
];
$htmlMail = AlertService::buildEmailHtml($printers, 1700000000);
check('email: escapes printer names', strpos($htmlMail, '<script>') === false && strpos($htmlMail, '&lt;script&gt;') !== false);
check('email: needs-attention section first', strpos($htmlMail, 'Needs attention') !== false && strpos($htmlMail, 'All good') !== false);
check('email: bar width 72% present', strpos($htmlMail, 'width="72%"') !== false);
check('email: offline badge present', strpos($htmlMail, 'OFFLINE') !== false);
check('email: table-based (outlook safe)', substr_count($htmlMail, '<table') >= 6);
$textMail = AlertService::buildEmailText($printers, 1700000000);
check('text: plain alternative has levels', strpos($textMail, 'Black: 72%') !== false);



// ---- regression: evaluate() with getAlertStates() shape (string states) ----
$printerEval = [
    'status' => 'offline',
    'levels' => [],
    'info' => ['last_levels' => '{"black":{"percentage":5},"cyan":{"percentage":45},"magenta":{"percentage":10},"yellow":{"percentage":0}}']
];
$prevRows = [33 => [AlertService::KIND_LOW_INK => '["magenta","yellow"]', AlertService::KIND_OFFLINE => 'offline']];
$eval = AlertService::evaluate($printerEval, $prevRows[33], 15, true, true);
check('evaluate: black crossing threshold notifies', $eval['notify'] === true);
check('evaluate: note mentions black', strpos(implode(' ', $eval['notes']), 'Black') !== false);
check('evaluate: offline unchanged stays silent', $eval['offline']['notify'] === false);
$evalBase = AlertService::evaluate($printerEval, null, 15, true, true);
check('evaluate: null prev = baseline, silent', $evalBase['notify'] === false);


// ---- SmtpClient pure parts ----
require __DIR__ . '/../src/SmtpClient.php';
$sasl = SmtpClient::buildXoauth2String('no-reply@ort.lt', 'TOKEN123');
check('xoauth2: valid base64', base64_decode($sasl, true) === "user=no-reply@ort.lt\x01auth=Bearer TOKEN123\x01\x01");
$c = new SmtpClient('smtp.office365.com', 587, 'auto');
$r = new ReflectionProperty('SmtpClient', 'secureMode');
$r->setAccessible(true);
check('smtp: port 587 auto -> tls', $r->getValue($c) === 'tls');
$c465 = new SmtpClient('smtp.office365.com', 465, 'auto');
check('smtp: port 465 auto -> ssl', $r->getValue($c465) === 'ssl');
$c1025 = new SmtpClient('relay.lan', 1025, 'auto');
check('smtp: custom port auto -> none', $r->getValue($c1025) === 'none');


// ---- Database::sanitizeSetting (CRLF injection / charset defense) ----
check('sanitize: CRLF in smtp_user stripped', Database::sanitizeSetting('smtp_user', "no-reply@ort.lt\r\nBcc: x@y.z") === 'no-reply@ort.ltBcc: x@y.z');
check('sanitize: pure-CRLF from_name rejected', Database::sanitizeSetting('smtp_from_name', "\r\n") === null);
check('sanitize: smtp_host charset', Database::sanitizeSetting('smtp_host', 'smtp.office365.com') === 'smtp.office365.com');
check('sanitize: smtp_host injection rejected', Database::sanitizeSetting('smtp_host', 'h\r\nX: 1') === null);
check('sanitize: smtp_host junk rejected', Database::sanitizeSetting('smtp_host', 'not a host!') === null);
check('sanitize: port range ok', Database::sanitizeSetting('smtp_port', '587') === '587');
check('sanitize: port 99999 rejected', Database::sanitizeSetting('smtp_port', '99999') === null);
check('sanitize: threshold clamped 1-100', Database::sanitizeSetting('alert_threshold', '999') === '100');
check('sanitize: threshold junk rejected', Database::sanitizeSetting('alert_threshold', 'abc') === null);
check('sanitize: oauth tenant charset', Database::sanitizeSetting('oauth_tenant', '../../evil') === null);
check('sanitize: oauth client id ok', Database::sanitizeSetting('oauth_client_id', '1234-abcd-5678') === '1234-abcd-5678');
check('sanitize: array value dropped', Database::sanitizeSetting('alert_recipients', ['a']) === null);
check('sanitize: secret key still sanitized', Database::sanitizeSetting('smtp_pass', "pw\r\nX") === 'pwX');


// ---- Database::sanitizeSnmpAuth / snmpAuthView ----
$v3 = '{"version":"3","community":"public","v3_level":"authPriv","v3_user":"mon","v3_auth_proto":"SHA","v3_auth_pass":"authpass1","v3_priv_proto":"AES","v3_priv_pass":"privpass1"}';
$clean = Database::sanitizeSnmpAuth($v3);
check('snmpAuth: valid v3 normalized', $clean !== null && json_decode($clean, true)['v3_user'] === 'mon');
check('snmpAuth: invalid version -> 2c', json_decode(Database::sanitizeSnmpAuth('{"version":"9","community":"c"}'), true)['version'] === '2c');
check('snmpAuth: v3 without user rejected', Database::sanitizeSnmpAuth('{"version":"3","v3_user":""}') === null);
check('snmpAuth: v3 authPriv without priv pass rejected', Database::sanitizeSnmpAuth('{"version":"3","v3_user":"u","v3_auth_pass":"p","v3_level":"authPriv"}') === null);
check('snmpAuth: authNoPriv without priv pass ok', Database::sanitizeSnmpAuth('{"version":"3","v3_user":"u","v3_level":"authNoPriv","v3_auth_pass":"p"}') !== null);
check('snmpAuth: CRLF stripped from community', strpos(Database::sanitizeSnmpAuth('{"community":"pub\r\nlic"}'), "\r") === false);
check('snmpAuth: junk rejected', Database::sanitizeSnmpAuth('not json') === null);
check('snmpAuth: empty rejected', Database::sanitizeSnmpAuth('') === null);
$view = Database::snmpAuthView($clean);
check('snmpAuthView: no passphrases leak', !isset($view['v3_auth_pass'], $view['v3_priv_pass']));
check('snmpAuthView: flags set', $view['has_auth_pass'] === true && $view['has_priv_pass'] === true);
check('snmpAuthView: null input -> null', Database::snmpAuthView(null) === null);

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
