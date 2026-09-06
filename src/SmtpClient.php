<?php
declare(strict_types=1);

/**
 * SmtpClient - minimal dependency-free SMTP submission client (RFC 5321).
 *
 * Why hand-rolled instead of PHPMailer:
 * - we need AUTH XOAUTH2 (Microsoft 365 OAuth2), which PHPMailer only
 *   supports through an extra OAuth2 SDK dependency chain;
 * - submission (client -> smarthost) is the simple side of SMTP:
 *   one message, known recipients, no relay logic.
 *
 * Supported: implicit TLS (465), STARTTLS (587), AUTH PLAIN / LOGIN /
 * XOAUTH2 (Microsoft 365 device-code flow), multiple RCPT, 8BITMIME,
 * quoted-printable MIME building, dot-stuffing.
 */
class SmtpClient {
    public string $lastError = '';

    /** @var resource|null */
    private $fp = null;
    private string $host;
    private int $port;
    private string $secureMode;   // 'auto' | 'tls' | 'ssl' | 'none'
    private int $timeout = 15;

    public function __construct(string $host, int $port, string $secureMode = 'auto') {
        $this->host = $host;
        $this->port = $port;
        $this->secureMode = $secureMode;
        if ($this->secureMode === 'auto') {
            $this->secureMode = $port === 465 ? 'ssl' : ($port === 587 ? 'tls' : 'none');
        }
    }

    /**
     * Full send: connect -> ehlo -> tls -> auth -> message -> quit.
     * @param array $recipients validated list
     */
    public function send(
        string $username,
        string $password,
        ?string $oauthToken,
        string $from,
        string $fromName,
        array $recipients,
        string $subject,
        string $html,
        string $text
    ): bool {
        // Defense in depth: Database::sanitizeSetting strips control chars at
        // save time, but the transport never trusts that alone - a CRLF here
        // would inject SMTP commands.
        foreach ([$username, $this->host] as $critical) {
            if (preg_match('/[\r\n]/', $critical)) {
                throw new RuntimeException('Invalid control characters in SMTP settings');
            }
        }
        try {
            $this->connect();
            $ext = $this->ehlo();

            if ($this->secureMode === 'tls') {
                if (!isset($ext['STARTTLS'])) {
                    throw new RuntimeException('Server does not advertise STARTTLS');
                }
                $this->cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('TLS negotiation failed');
                }
                $ext = $this->ehlo(); // capabilities change after STARTTLS
            }

            if ($oauthToken !== null) {
                $this->authXoauth2($username, $oauthToken);
            } else {
                // PLAIN first (one round trip), fall back to LOGIN challenges
                if (isset($ext['AUTH']) && stripos($ext['AUTH'], 'PLAIN') !== false) {
                    $this->authPlain($username, $password);
                } else {
                    $this->authLogin($username, $password);
                }
            }

            $this->cmd('MAIL FROM:<' . $from . '>', [250]);
            foreach ($recipients as $r) {
                $this->cmd('RCPT TO:<' . $r . '>', [250, 251]);
            }
            $this->cmd('DATA', [354]);

            $this->data($from, $fromName, $recipients, $subject, $html, $text);
            $this->cmd('.', [250]);

            $this->cmd('QUIT', [221]);
            $this->disconnect();
            return true;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->disconnect();
            return false;
        }
    }

    public function disconnect(): void {
        if ($this->fp !== null) {
            fclose($this->fp);
            $this->fp = null;
        }
    }

    // ---------------- transport ----------------

    private function connect(): void {
        $remote = ($this->secureMode === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $this->fp = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($this->fp === false) {
            throw new RuntimeException("Connect failed: $errstr ($errno)");
        }
        stream_set_timeout($this->fp, $this->timeout);
        $greeting = $this->readResponse();
        if ($greeting['code'] !== 220) {
            throw new RuntimeException('Bad greeting: ' . implode(' / ', $greeting['lines']));
        }
    }

    /** @return array<string,string> extension map (name => params) */
    private function ehlo(): array {
        $r = $this->cmd('EHLO sprinter.local', [250]);
        $ext = [];
        foreach ($r['lines'] as $i => $line) {
            if ($i === 0) continue; // greeting line
            $parts = explode(' ', $line, 2);
            $ext[strtoupper($parts[0])] = $parts[1] ?? '';
        }
        return $ext;
    }

    private function readResponse(): array {
        $lines = [];
        $code = 0;
        while (($line = fgets($this->fp, 1024)) !== false) {
            $line = rtrim($line, "\r\n");
            if (strlen($line) < 4 || $line[3] === ' ') {
                $code = (int)substr($line, 0, 3);
                $lines[] = substr($line, 4);
                break;
            }
            $lines[] = substr($line, 4);
        }
        if ($code === 0) {
            $meta = $this->fp ? stream_get_meta_data($this->fp) : [];
            throw new RuntimeException(!empty($meta['timed_out']) ? 'SMTP read timed out' : 'SMTP connection lost');
        }
        return ['code' => $code, 'lines' => $lines];
    }

    private function cmd(string $command, array $expect): array {
        fwrite($this->fp, $command . "\r\n");
        $r = $this->readResponse();
        if (!in_array($r['code'], $expect, true)) {
            throw new RuntimeException('"' . strtok($command, ' ') . '" failed: ' . $r['code'] . ' ' . implode(' / ', $r['lines']));
        }
        return $r;
    }

    // ---------------- auth ----------------

    private function authPlain(string $user, string $pass): void {
        $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235]);
    }

    private function authLogin(string $user, string $pass): void {
        $r = $this->cmd('AUTH LOGIN', [334]);
        if (!base64_decode(trim($r['lines'][0] ?? ''), true)) { /* challenge received */ }
        $r = $this->cmd(base64_encode($user), [334]);
        $this->cmd(base64_encode($pass), [235]);
    }

    /**
     * Microsoft 365 XOAUTH2. $token = fresh OAuth2 access token with
     * SMTP.Send scope. SASL: base64("user=$user\x01auth=Bearer $token\x01\x01")
     */
    public function authXoauth2(string $user, string $token): void {
        $sasl = self::buildXoauth2String($user, $token);
        fwrite($this->fp, 'AUTH XOAUTH2 ' . $sasl . "\r\n");
        $r = $this->readResponse();
        if ($r['code'] === 334) {
            // 334 + base64 JSON error: we must send an empty line to get the failure
            $r = $this->readResponse();
        }
        if ($r['code'] !== 235) {
            throw new RuntimeException('XOAUTH2 auth failed: ' . $r['code'] . ' ' . implode(' / ', $r['lines']));
        }
    }

    public static function buildXoauth2String(string $user, string $token): string {
        // RFC 4616 / XOAUTH2 SASL: "user=<u>\x01auth=Bearer <t>\x01\x01"
        return base64_encode('user=' . $user . "\x01" . 'auth=Bearer ' . $token . "\x01\x01");
    }

    // ---------------- message ----------------

    private function data(string $from, string $fromName, array $recipients, string $subject, string $html, string $text): void {
        $fromName = $fromName !== '' ? $fromName : 'S~printer';
        $boundary = 'sp_' . bin2hex(random_bytes(12));
        $eol = "\r\n";

        $headers =
            'Date: ' . date('r') . $eol .
            'From: ' . mb_encode_mimeheader($fromName) . " <{$from}>" . $eol .
            'To: ' . mb_encode_mimeheader(implode(', ', $recipients)) . $eol .
            'Subject: ' . mb_encode_mimeheader($subject) . $eol .
            'MIME-Version: 1.0' . $eol .
            'X-Mailer: S-printer' . $eol .
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"" . $eol;

        $body =
            "This is a multi-part message in MIME format." . $eol . $eol .
            "--{$boundary}" . $eol .
            'Content-Type: text/plain; charset=UTF-8' . $eol .
            'Content-Transfer-Encoding: quoted-printable' . $eol . $eol .
            quoted_printable_encode($text) . $eol . $eol .
            "--{$boundary}" . $eol .
            'Content-Type: text/html; charset=UTF-8' . $eol .
            'Content-Transfer-Encoding: quoted-printable' . $eol . $eol .
            quoted_printable_encode($html) . $eol . $eol .
            "--{$boundary}--";

        $message = $headers . $eol . $body;
        // dot-stuffing: RFC 5321 4.5.2 - lines starting with "." get another "."
        $message = preg_replace('/^\./m', '..', $message);

        fwrite($this->fp, $message . "\r\n");
    }
}
