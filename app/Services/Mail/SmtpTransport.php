<?php

declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;

/**
 * Minimal, dependency-free SMTP client: implicit TLS (465) or STARTTLS (587),
 * AUTH LOGIN, UTF-8 text + HTML (multipart/alternative). TLS certificates are
 * verified; plain-text SMTP is refused. Credentials are never logged or echoed
 * in exceptions (server replies are truncated and contain no secrets).
 */
final class SmtpTransport
{
    /** @var resource|null */
    private $socket = null;

    /** @param array{host:string,port:int,encryption:string,username:string,password:string,timeout:int} $cfg */
    public function __construct(private array $cfg)
    {
    }

    public function send(string $fromAddress, string $fromName, string $to, string $subject, string $text, ?string $html = null): void
    {
        foreach ([$fromAddress, $to] as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $addr)) {
                throw new RuntimeException('invalid address');
            }
        }
        $enc = $this->cfg['encryption'];
        if (!in_array($enc, ['tls', 'ssl'], true)) {
            throw new RuntimeException('MAIL_ENCRYPTION must be tls or ssl');
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->cfg['host']]]);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $this->cfg['host'] . ':' . $this->cfg['port'];
        $socket = @stream_socket_client($remote, $errno, $errstr, max(1, $this->cfg['timeout']), STREAM_CLIENT_CONNECT, $ctx);
        if (!is_resource($socket)) {
            throw new RuntimeException('connection failed');
        }
        $this->socket = $socket;
        stream_set_timeout($socket, max(1, $this->cfg['timeout']));
        try {
            $this->expect(220);
            $helo = gethostname() ?: 'localhost';
            $this->command("EHLO $helo", 250);
            if ($enc === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('TLS negotiation failed');
                }
                $this->command("EHLO $helo", 250);
            }
            if ($this->cfg['username'] !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->cfg['username']), 334, true);
                $this->command(base64_encode($this->cfg['password']), 235, true);
            }
            $this->command('MAIL FROM:<' . $fromAddress . '>', 250);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', 354);
            $this->write($this->message($fromAddress, $fromName, $to, $subject, $text, $html) . "\r\n.");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            fclose($socket);
            $this->socket = null;
        }
    }

    /** RFC 5322 message; every line dot-stuffed for DATA. */
    public function message(string $fromAddress, string $fromName, string $to, string $subject, string $text, ?string $html): string
    {
        $enc = static fn (string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . $enc($fromName) . ' <' . $fromAddress . '>',
            'To: <' . $to . '>',
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . (explode('@', $fromAddress)[1] ?? 'localhost') . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
        ];
        $part = static fn (string $type, string $body) => "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
        if ($html === null) {
            $body = implode("\r\n", $headers) . "\r\n" . $part('text/plain', $text);
        } else {
            $b = 'b' . bin2hex(random_bytes(12));
            $body = implode("\r\n", $headers) . "\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n"
                . "--$b\r\n" . $part('text/plain', $text) . "\r\n--$b\r\n" . $part('text/html', $html) . "\r\n--$b--";
        }
        return preg_replace('/^\./m', '..', $body) ?? $body;
    }

    /** @param int|list<int> $ok */
    private function command(string $line, int|array $ok, bool $secret = false): void
    {
        $this->write($line);
        $this->expect($ok, $secret);
    }

    private function write(string $data): void
    {
        if ($this->socket === null || @fwrite($this->socket, $data . "\r\n") === false) {
            throw new RuntimeException('write failed');
        }
    }

    /** @param int|list<int> $ok */
    private function expect(int|array $ok, bool $secret = false): void
    {
        $reply = '';
        while ($this->socket !== null && ($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, (array) $ok, true)) {
            // Never echo what was sent (credentials); only the server's code and short text.
            throw new RuntimeException('SMTP ' . ($code ?: 'no reply') . ($secret ? '' : ' ' . mb_substr(trim(substr($reply, 4)), 0, 120)));
        }
    }
}
