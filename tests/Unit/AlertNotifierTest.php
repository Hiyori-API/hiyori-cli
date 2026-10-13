<?php

declare(strict_types=1);

namespace Hiyori\Tests\Unit;

use Hiyori\Service\AlertNotifier;
use Hiyori\Service\Telemetry;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for the multi-channel alert notifier. No network I/O:
 * the configured channels are driven by $_ENV, which these tests control.
 */
final class AlertNotifierTest extends TestCase
{
    private string $tmp;

    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->savedEnv = $_ENV;
        $this->tmp = sys_get_temp_dir() . '/hiyori-alertnotifier-' . uniqid();
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->savedEnv;
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
    }

    private function clearChannels(): void
    {
        foreach (['WEBHOOK_URL', 'TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID', 'ALERT_EMAIL_TO', 'SMTP_HOST'] as $key) {
            $this->setEnv($key, '');
        }
    }

    public function testDeliverWithNoChannelsIsNoOp(): void
    {
        $this->clearChannels();
        $telemetry = new Telemetry($this->tmp);

        AlertNotifier::deliver([
            ['level' => 'critical', 'type' => 'circuit_open', 'source' => 'anilist', 'msg' => 'Circuit breaker open for anilist'],
        ], $telemetry);

        $this->assertSame([], AlertNotifier::channels());
        $this->assertFileDoesNotExist($this->tmp . '/alerts-sent.json');
    }

    public function testDeliverWithEmptyAlertsIsNoOp(): void
    {
        $this->setEnv('WEBHOOK_URL', 'https://example.test/hook');
        $telemetry = new Telemetry($this->tmp);

        AlertNotifier::deliver([], $telemetry);

        $this->assertFileDoesNotExist($this->tmp . '/alerts-sent.json');
    }

    public function testDeliverRespectsExistingThrottleStateWithoutNetwork(): void
    {
        $this->clearChannels();
        $this->setEnv('TELEGRAM_BOT_TOKEN', '123456:test-secret');
        $this->setEnv('TELEGRAM_CHAT_ID', 'chat-1');

        $alert = ['level' => 'critical', 'type' => 'api_down', 'source' => '*', 'msg' => 'APIs unreachable'];
        $key = AlertNotifier::throttleKey('telegram', $alert);

        $telemetry = new Telemetry($this->tmp);
        $telemetry->update('alerts-sent', [$key => time()]); // sent just now
        $file = $this->tmp . '/alerts-sent.json';
        $before = (string) file_get_contents($file);

        AlertNotifier::deliver([$alert], $telemetry);

        // Still throttled → nothing delivered, nothing rewritten.
        $this->assertSame($before, (string) file_get_contents($file));
    }

    public function testAlertIdentityAndThrottleKeyBuilder(): void
    {
        $alert = ['type' => 'circuit_open', 'source' => 'anilist'];

        $this->assertSame('circuit_open:anilist', AlertNotifier::alertIdentity($alert));
        $this->assertSame('telegram:circuit_open:anilist', AlertNotifier::throttleKey('telegram', $alert));
        $this->assertSame('email:circuit_open:anilist', AlertNotifier::throttleKey('email', $alert));
        $this->assertSame('webhook:circuit_open:anilist', AlertNotifier::throttleKey('webhook', $alert));
    }

    public function testDueFiltersWithinThrottleWindow(): void
    {
        $alerts = [
            ['type' => 'circuit_open', 'source' => 'anilist'],
            ['type' => 'low_tokens', 'source' => 'kitsu'],
        ];
        $sent = ['telegram:circuit_open:anilist' => 999500];

        // circuit_open was sent 500s ago (inside the 1h window) → skipped;
        // low_tokens was never sent → due.
        $due = AlertNotifier::due($alerts, 'telegram', $sent, 1000000);
        $this->assertCount(1, $due);
        $this->assertSame('telegram:low_tokens:kitsu', $due[0]['key']);

        // Nothing sent recently → both due.
        $due = AlertNotifier::due($alerts, 'telegram', [], 1000000);
        $this->assertCount(2, $due);
    }

    public function testDueBoundaryAtOneHour(): void
    {
        $alert = ['type' => 'circuit_open', 'source' => 'anilist'];
        $key = 'webhook:circuit_open:anilist';

        $this->assertSame([], AlertNotifier::due([$alert], 'webhook', [$key => 0], 3599));
        $this->assertCount(1, AlertNotifier::due([$alert], 'webhook', [$key => 0], 3600));
    }

    public function testAlertLineShape(): void
    {
        $line = AlertNotifier::alertLine([
            'level' => 'warn',
            'type' => 'api_degraded',
            'source' => '*',
            'msg' => 'External APIs degraded',
        ]);
        $this->assertSame('[Hiyori] WARN api_degraded (*): External APIs degraded', $line);
    }

    public function testWebhookPayloadMatchesLegacyContract(): void
    {
        $this->assertSame(
            ['text' => '[Hiyori] Circuit breaker open for anilist'],
            AlertNotifier::webhookPayload(['msg' => 'Circuit breaker open for anilist']),
        );
    }

    public function testEscapeMarkdownEscapesSpecialCharacters(): void
    {
        $this->assertSame('a\\_b \\*c\\* \\[x\\]', AlertNotifier::escapeMarkdown('a_b *c* [x]'));
    }

    public function testTelegramTextEscapesMarkdownAndRedactsSecrets(): void
    {
        $this->setEnv('TELEGRAM_BOT_TOKEN', '123456:abc-secret');
        $text = AlertNotifier::telegramText([
            ['level' => 'critical', 'type' => 'circuit_open', 'source' => 'anilist', 'msg' => 'msg with _underscore_ 123456:abc-secret'],
        ]);

        $this->assertStringNotContainsString('123456:abc-secret', $text);
        $this->assertStringContainsString('\\*\\*\\*', $text); // masked secret, Markdown-escaped
        $this->assertStringContainsString('\\_', $text);
    }

    public function testMaskSecretsRedactsValues(): void
    {
        $this->assertSame(
            'token *** and ***',
            AlertNotifier::maskSecrets('token abc123 and xyz9', ['abc123', 'xyz9']),
        );
    }

    public function testEmailMessageStructureAndNoSecretLeak(): void
    {
        $this->setEnv('SMTP_PASS', 'smtp-supersecret');
        $msg = AlertNotifier::emailMessage('alerts@hiyori.local', 'ops@example.com', [
            ['level' => 'critical', 'type' => 'api_down', 'source' => '*', 'msg' => 'APIs unreachable'],
        ]);

        $this->assertStringStartsWith('From: alerts@hiyori.local', $msg);
        $this->assertStringContainsString('To: ops@example.com', $msg);
        $this->assertStringContainsString('Subject: [Hiyori] 1 alert', $msg);
        $this->assertStringContainsString('MIME-Version: 1.0', $msg);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $msg);
        $this->assertStringContainsString('[Hiyori] CRITICAL api_down (*): APIs unreachable', $msg);
        $this->assertStringNotContainsString('smtp-supersecret', $msg);
    }
}
