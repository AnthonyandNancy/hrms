<?php

declare(strict_types=1);

namespace Tests\Unit\IAM;

use App\Modules\IAM\Services\TotpService;
use PHPUnit\Framework\TestCase;

/**
 * RFC 6238 Appendix B test vectors using the ASCII seed
 * "12345678901234567890" (Base32 GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ).
 */
final class TotpTest extends TestCase
{
    public function test_rfc6238_sha1_test_vectors(): void
    {
        $totp = new TotpService;
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $vectors = [
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ];

        foreach ($vectors as $timestamp => $expected) {
            $this->assertSame(
                $expected,
                $totp->code($secret, $timestamp, 8),
                "RFC 6238 vector failed at T={$timestamp}.",
            );
        }
    }

    public function test_code_uses_six_digits_and_thirty_second_step(): void
    {
        $totp = new TotpService;
        $secret = 'JBSWY3DPEHPK3PXP';

        $this->assertMatchesRegularExpression('/^\d{6}$/', $totp->code($secret, 59));

        // 30s buckets: 0-29 share a code, 30-59 share the next.
        $this->assertSame($totp->code($secret, 0), $totp->code($secret, 29));
        $this->assertNotSame($totp->code($secret, 0), $totp->code($secret, 30));
    }

    public function test_verification_accepts_only_plus_or_minus_one_window(): void
    {
        $totp = new TotpService;
        $secret = 'JBSWY3DPEHPK3PXP';
        $now = 120;
        $current = $totp->code($secret, $now);

        $this->assertTrue($totp->verify($secret, $current, $now));
        $this->assertTrue($totp->verify($secret, $totp->code($secret, $now - 30), $now));
        $this->assertTrue($totp->verify($secret, $totp->code($secret, $now + 30), $now));
        $this->assertFalse($totp->verify($secret, $totp->code($secret, $now - 60), $now));
        $this->assertFalse($totp->verify($secret, $totp->code($secret, $now + 60), $now));
        $this->assertFalse($totp->verify($secret, '000000', $now));
    }

    public function test_invalid_base32_or_non_numeric_code_is_rejected(): void
    {
        $totp = new TotpService;

        $this->assertFalse($totp->verify('NOT-BASE-32!', '123456', 0));
        $this->assertFalse($totp->verify('JBSWY3DPEHPK3PXP', 'abcdef', 0));
        $this->assertFalse($totp->verify('JBSWY3DPEHPK3PXP', '12345', 0));
    }

    public function test_base32_round_trip_and_constant_time_comparison(): void
    {
        $totp = new TotpService;
        $raw = random_bytes(20);

        $this->assertSame($raw, $totp->base32Decode($totp->base32Encode($raw)));

        $this->assertTrue($totp->verify('JBSWY3DPEHPK3PXP', $totp->code('JBSWY3DPEHPK3PXP', 120), 120));
    }
}
