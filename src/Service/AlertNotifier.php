<?php

declare(strict_types=1);

namespace Hiyori\Service;

/**
 * Best-effort multi-channel delivery for dashboard alerts.
 *
 * Reads channel configuration from the environment (WEBHOOK_URL,
 * TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID, SMTP_* + ALERT_EMAIL_TO) and
 * sends each
 * alert to every configured channel, throttled once per hour per
 * channel+alert identity in the `alerts-sent` telemetry store.
 *
 * Transport failures are swallowed: alerting is fire-and-forget and this
 * service never throws.
 */
final class AlertNotifier
{
    /** Throttle window: one delivery per channel+alert identity per hour. */
    public const THROTTLE_SECONDS = 3600;

    private function __construct()
    {
    }

    /**
     * Deliver $alerts to every configured channel (throttled).
     *
     * @param list<array<string, mixed>> $alerts
     */
    public static function deliver(array $alerts, Telemetry $telemetry): void
    {
        if ($alerts === []) {
            return;
        }
        $sent = $telemetry->read('alerts-sent');
        $now = time();
        $changed = false;

        foreach (self::channels() as $channel) {
            $due = self::due($alerts, $channel, $sent, $now);
            if ($due === []) {
                continue;
            }
            self::sendChannel($channel, $due);
            foreach ($due as $item) {
                $sent[$item['key']] = $now;
            }
            $changed = true;
        }

        if ($changed) {
            $telemetry->update('alerts-sent', $sent);
        }
    }

    /**
     * Channels currently fully configured (webhook / telegram / email).
     *
     * @return list<string>
     */
    public static function channels(): array
    {
        $channels = [];
        if (self::env('WEBHOOK_URL') !== '') {
            $channels[] = 'webhook';
        }
        if (self::env('TELEGRAM_BOT_TOKEN') !== '' && self::env('TELEGRAM_CHAT_ID') !== '') {
            $channels[] = 'telegram';
        }
        if (self::env('ALERT_EMAIL_TO') !== '' && (self::env('SMTP_HOST') !== '' || function_exists('mail'))) {
            $channels[] = 'email';
        }

        return $channels;
    }

    /**
     * Alerts not yet delivered on $channel within the throttle window.
     *
     * @param array $alerts alert items (each at least type/source/msg)
     * @param array $sent   alerts-sent map (throttle key => last send ts)
     *
     * @return list<array{alert: array, key: string}>
     */
    public static function due(array $alerts, string $channel, array $sent, int $now): array
    {
        $due = [];
        foreach ($alerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }
            $key = self::throttleKey($channel, $alert);
            if ($now - (int) ($sent[$key] ?? 0) >= self::THROTTLE_SECONDS) {
                $due[] = ['alert' => $alert, 'key' => $key];
            }
        }

        return $due;
    }

    /**
     * Per-channel throttle key, e.g. "telegram:circuit_open:anilist".
     *
     * @param array $alert
     */
    public static function throttleKey(string $channel, array $alert): string
    {
        return $channel . ':' . self::alertIdentity($alert);
    }

    /**
     * Channel-independent alert identity (type:source).
     *
     * @param array $alert
     */
    public static function alertIdentity(array $alert): string
    {
        return (string) ($alert['type'] ?? '') . ':' . (string) ($alert['source'] ?? '');
    }

    /**
     * One human-readable line per alert, e.g.
     * "[Hiyori] CRITICAL circuit_open (anilist): Circuit breaker open for anilist".
     *
     * @param array $alert
     */
    public static function alertLine(array $alert): string
    {
        $level = strtoupper((string) ($alert['level'] ?? 'info'));
        $type = (string) ($alert['type'] ?? 'unknown');
        $source = (string) ($alert['source'] ?? '');
        $msg = (string) ($alert['msg'] ?? '');

        return sprintf('[Hiyori] %s %s%s: %s', $level, $type, $source !== '' ? ' (' . $source . ')' : '', $msg);
    }

    /**
     * Plain-text multi-alert body (e-mail content).
     *
     * @param array $alerts
     */
    public static function text(array $alerts): string
    {
        $lines = [];
        foreach ($alerts as $alert) {
            if (is_array($alert)) {
                $lines[] = self::alertLine($alert);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Markdown-escaped, secret-masked body for Telegram (parse_mode=Markdown).
     *
     * @param array $alerts
     */
    public static function telegramText(array $alerts): string
    {
        $lines = [];
        foreach ($alerts as $alert) {
            if (is_array($alert)) {
                // Redact before escaping so a secret is replaced while still
                // in its raw form (escaping would otherwise mask its shape).
                $lines[] = self::escapeMarkdown(self::maskSecrets(self::alertLine($alert)));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Escape Telegram Markdown special characters so alert text renders as
     * plain text instead of formatting.
     */
    public static function escapeMarkdown(string $text): string
    {
        return (string) preg_replace('/([_*\[\]()~`>#+\-=|{}.!])/', '\\\\$1', $text);
    }

    /**
     * Redact configured secrets from a message so a value that sneaks into an
     * alert payload is never echoed to a channel.
     *
     * @param list<string>|null $secrets
     */
    public static function maskSecrets(string $text, ?array $secrets = null): string
    {
        foreach ($secrets ?? self::configuredSecrets() as $secret) {
            $secret = (string) $secret;
            if ($secret !== '' && str_contains($text, $secret)) {
                $text = str_replace($secret, '***', $text);
            }
        }

        return $text;
    }

    /**
     * Webhook payload for one alert — matches the historical alerts()
     * delivery contract exactly.
     *
     * @param array $alert
     *
     * @return array{text: string}
     */
    public static function webhookPayload(array $alert): array
    {
        return ['text' => '[Hiyori] ' . (string) ($alert['msg'] ?? '')];
    }

    /**
     * @param array $alerts
     */
    public static function emailSubject(array $alerts): string
    {
        $count = 0;
        foreach ($alerts as $alert) {
            if (is_array($alert)) {
                ++$count;
            }
        }

        return '[Hiyori] ' . $count . ' alert' . ($count === 1 ? '' : 's');
    }

    /**
     * mail() fallback headers (used when no SMTP_HOST is configured).
     */
    public static function emailHeaders(string $from): string
    {
        return "From: " . self::address($from) . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
            . "Content-Transfer-Encoding: 8bit";
    }

    /**
     * Full RFC5322 message (CRLF line endings) as sent over SMTP.
     *
     * @param array $alerts
     */
    public static function emailMessage(string $from, string $to, array $alerts): string
    {
        $headers = [
            'From: ' . self::address($from),
            'To: ' . self::address($to),
            'Subject: ' . self::emailSubject($alerts),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . self::text($alerts) . "\r\n";
    }

    /**
     * Send through the configured channel. Never throws.
     *
     * @param list<array{alert: array, key: string}> $due
     */
    private static function sendChannel(string $channel, array $due): void
    {
        try {
            if ($channel === 'webhook') {
                self::sendWebhook($due);
            } elseif ($channel === 'telegram') {
                self::sendTelegram($due);
            } elseif ($channel === 'email') {
                self::sendEmail($due);
            }
        } catch (\Throwable) {
            // best-effort: never escalate a delivery failure
        }
    }

    /**
     * One POST per alert to WEBHOOK_URL (identical payload to the historical
     * delivery, 5s timeout).
     *
     * @param list<array{alert: array, key: string}> $due
     */
    private static function sendWebhook(array $due): void
    {
        $url = self::env('WEBHOOK_URL');
        if ($url === '') {
            return;
        }
        foreach ($due as $item) {
            @file_get_contents($url, false, self::postContext(
                (string) json_encode(self::webhookPayload($item['alert'])),
                'application/json',
            ));
        }
    }

    /**
     * One Telegram message per batch of due alerts (Markdown, 5s timeout).
     *
     * @param list<array{alert: array, key: string}> $due
     */
    private static function sendTelegram(array $due): void
    {
        $token = self::env('TELEGRAM_BOT_TOKEN');
        $chatId = self::env('TELEGRAM_CHAT_ID');
        if ($token === '' || $chatId === '') {
            return;
        }
        $alerts = [];
        foreach ($due as $item) {
            $alerts[] = $item['alert'];
        }
        $payload = [
            'chat_id' => $chatId,
            'text' => self::telegramText($alerts),
            'parse_mode' => 'Markdown',
        ];
        try {
            $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
            @file_get_contents($url, false, self::postContext((string) json_encode($payload), 'application/json'));
        } catch (\Throwable) {
            // best-effort
        }
    }

    /**
     * E-mail batch via SMTP (SMTP_HOST set) or the mail() fallback.
     *
     * @param list<array{alert: array, key: string}> $due
     */
    private static function sendEmail(array $due): void
    {
        $to = self::env('ALERT_EMAIL_TO');
        if ($to === '') {
            return;
        }
        $alerts = [];
        foreach ($due as $item) {
            $alerts[] = $item['alert'];
        }
        $from = self::emailFrom();
        $host = self::env('SMTP_HOST');

        if ($host !== '') {
            self::sendBySmtp($host, $from, $to, $alerts);

            return;
        }
        if (function_exists('mail')) {
            @mail($to, self::emailSubject($alerts), self::text($alerts), self::emailHeaders($from));
        }
    }

    /**
     * Minimal RFC5321 client: EHLO, optional STARTTLS, AUTH LOGIN, one
     * message. Best-effort — connection/command failures are swallowed.
     *
     * @param array $alerts
     */
    private static function sendBySmtp(string $host, string $from, string $to, array $alerts): void
    {
        $port = self::env('SMTP_PORT') !== '' ? (int) self::env('SMTP_PORT') : 25;
        $secure = strtolower(self::env('SMTP_SECURE'));
        $user = self::env('SMTP_USER');
        $pass = self::env('SMTP_PASS');
        $message = self::emailMessage($from, $to, $alerts);

        $errno = 0;
        $errstr = '';
        $conn = @fsockopen(($secure === 'ssl' ? 'ssl://' : '') . $host, $port, $errno, $errstr, 5);
        if ($conn === false) {
            return;
        }

        try {
            if (!str_starts_with(self::smtpRead($conn), '220')) {
                return;
            }
            if (!str_starts_with(self::smtpExchange($conn, 'EHLO localhost'), '250')) {
                return;
            }

            if ($secure === 'tls') {
                if (!str_starts_with(self::smtpExchange($conn, 'STARTTLS'), '220')) {
                    return;
                }
                if (!@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return;
                }
                if (!str_starts_with(self::smtpExchange($conn, 'EHLO localhost'), '250')) {
                    return;
                }
            }

            if ($user !== '' && $pass !== '') {
                if (!str_starts_with(self::smtpExchange($conn, 'AUTH LOGIN'), '334')) {
                    return;
                }
                if (!str_starts_with(self::smtpExchange($conn, base64_encode($user)), '334')) {
                    return;
                }
                if (!str_starts_with(self::smtpExchange($conn, base64_encode($pass)), '235')) {
                    return;
                }
            }

            if (!str_starts_with(self::smtpExchange($conn, 'MAIL FROM:<' . $from . '>'), '250')) {
                return;
            }
            if (!str_starts_with(self::smtpExchange($conn, 'RCPT TO:<' . $to . '>'), '250')) {
                return;
            }
            if (!str_starts_with(self::smtpExchange($conn, 'DATA'), '354')) {
                return;
            }
            $body = (string) preg_replace('/^\./m', '..', $message);
            @fwrite($conn, $body);
            if (!str_ends_with($body, "\r\n")) {
                @fwrite($conn, "\r\n");
            }
            if (!str_starts_with(self::smtpExchange($conn, '.'), '250')) {
                return;
            }

            self::smtpExchange($conn, 'QUIT');
        } finally {
            @fclose($conn);
        }
    }

    /**
     * Read one (possibly multi-line) SMTP reply.
     *
     * @param resource $conn
     */
    private static function smtpRead($conn): string
    {
        $reply = '';
        while (($line = @fgets($conn, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        return $reply;
    }

    /**
     * Send one command and read its reply.
     *
     * @param resource $conn
     */
    private static function smtpExchange($conn, string $command): string
    {
        @fwrite($conn, $command . "\r\n");

        return self::smtpRead($conn);
    }

    /**
     * @return list<string>
     */
    private static function configuredSecrets(): array
    {
        $secrets = [];
        foreach (['TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID', 'SMTP_USER', 'SMTP_PASS', 'WEBHOOK_URL'] as $key) {
            $value = self::env($key);
            if ($value !== '') {
                $secrets[] = $value;
            }
        }

        return $secrets;
    }

    private static function emailFrom(): string
    {
        $from = self::env('SMTP_FROM');
        if ($from !== '') {
            return $from;
        }
        $user = self::env('SMTP_USER');

        return $user !== '' ? $user : 'no-reply@localhost';
    }

    /**
     * Strip CR/LF from an e-mail address so env values can't inject headers.
     */
    private static function address(string $email): string
    {
        return trim((string) preg_replace('/[\r\n]+/', '', $email));
    }

    /**
     * POST stream context: JSON body + 5s timeout.
     *
     * @return resource
     */
    private static function postContext(string $json, string $contentType)
    {
        return stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: ' . $contentType,
                'content' => $json,
                'timeout' => 5,
            ],
        ]);
    }

    /**
     * Read a channel env var (Dotenv-populated $_ENV first, getenv fallback).
     */
    private static function env(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return is_string($value) ? trim($value) : '';
    }
}
