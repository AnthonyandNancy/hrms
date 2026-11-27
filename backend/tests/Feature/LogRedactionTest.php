<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Logging\SensitiveDataRedactor;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LogRedactionTest extends TestCase
{
    public function test_context_keys_are_redacted_at_every_depth(): void
    {
        $redactor = new SensitiveDataRedactor;
        $clean = $redactor->context([
            'password' => 'hunter2-plain',
            'nested' => [
                'token' => 'tok_live_ABC',
                'id_number' => '110101199001011234',
                'safe' => 'value',
            ],
        ]);

        $this->assertSame('[REDACTED]', $clean['password']);
        $this->assertSame('[REDACTED]', $clean['nested']['token']);
        $this->assertSame('[REDACTED]', $clean['nested']['id_number']);
        $this->assertSame('value', $clean['nested']['safe']);
    }

    public function test_identity_numbers_emails_and_mobiles_are_masked_in_free_text(): void
    {
        $redactor = new SensitiveDataRedactor;
        $message = $redactor->message('cannot import id 110101199001011234 email demo.user@example.test mobile 13800138000');

        $this->assertStringNotContainsString('110101199001011234', $message);
        $this->assertStringNotContainsString('demo.user@example.test', $message);
        $this->assertStringNotContainsString('13800138000', $message);
        $this->assertStringContainsString('[ID_NUMBER]', $message);
        $this->assertStringContainsString('[EMAIL]', $message);
        $this->assertStringContainsString('[MOBILE]', $message);
    }

    public function test_real_log_channel_redacts_context_and_message(): void
    {
        config()->set('logging.channels.redaction_test', [
            'driver' => 'single',
            'path' => storage_path('logs/redaction-test.log'),
            'level' => 'debug',
            'tap' => [\App\Modules\Platform\Logging\RedactingLogTap::class],
            'replace_placeholders' => true,
        ]);
        config()->set('logging.default', 'redaction_test');
        $path = storage_path('logs/redaction-test.log');

        if (is_file($path)) {
            unlink($path);
        }

        Log::channel('redaction_test')->info('login attempt password=SuperSecret123 id 110101199001011234', [
            'password' => 'SuperSecret123',
            'token' => 'tok_live_9f8e7d6c5b4a',
            'nested' => ['id_number' => '110101199001011234'],
        ]);
        Log::channel('redaction_test')->warning('safe line', ['request_id' => 'req-12345678', 'tenant_id' => 1]);

        $contents = (string) file_get_contents($path);

        foreach (['SuperSecret123', '110101199001011234', 'tok_live_9f8e7d6c5b4a'] as $secret) {
            $this->assertStringNotContainsString($secret, $contents);
        }

        $this->assertStringContainsString('req-12345678', $contents);
        $this->assertStringContainsString('[REDACTED]', $contents);
        $this->assertStringContainsString('[ID_NUMBER]', $contents);

        unlink($path);
    }

    public function test_request_log_line_contains_correlation_fields_and_no_payload(): void
    {
        config()->set('logging.channels.redaction_test', [
            'driver' => 'single',
            'path' => storage_path('logs/request-line-test.log'),
            'level' => 'debug',
            'tap' => [\App\Modules\Platform\Logging\RedactingLogTap::class],
            'replace_placeholders' => true,
        ]);
        config()->set('logging.default', 'redaction_test');
        $path = storage_path('logs/request-line-test.log');

        if (is_file($path)) {
            unlink($path);
        }

        $this->getJson('/v1/meta?password=SuperSecret123', ['X-Request-Id' => 'req-log-12345678']);
        $contents = (string) file_get_contents($path);
        $lines = array_values(array_filter(
            explode("\n", $contents),
            static fn (string $line): bool => str_contains($line, 'http.request'),
        ));

        $this->assertNotEmpty($lines, 'Expected a structured http.request log line.');
        $line = $lines[0];
        $this->assertStringContainsString('"request_id":"req-log-12345678"', $line);
        $this->assertStringContainsString('"status":200', $line);
        $this->assertStringContainsString('"duration_ms":', $line);
        $this->assertStringNotContainsString('SuperSecret123', $line);

        unlink($path);
    }
}
