<?php

declare(strict_types=1);

namespace Tests\Unit\People;

use App\Modules\People\Services\PersonPrivacy;
use Tests\TestCase;

final class PersonPrivacyTest extends TestCase
{
    public function test_identity_hash_is_deterministic_but_plaintext_independent(): void
    {
        config()->set('hrms.pii_hmac_key', 'unit-test-key');
        $privacy = app(PersonPrivacy::class);

        $first = $privacy->hashIdentity('NATIONAL_ID', ' 110101199001011234 ');
        $second = $privacy->hashIdentity('national_id', '110101199001011234');
        $otherKey = hash_hmac('sha256', 'NATIONAL_ID|110101199001011234', 'different-key');

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));
        $this->assertNotSame($otherKey, $first);
        $this->assertNotSame('110101199001011234', $first);
    }

    public function test_mobile_masking_never_returns_full_number(): void
    {
        $privacy = app(PersonPrivacy::class);

        $this->assertSame('*******1111', $privacy->maskedMobile('13800001111'));
        $this->assertNull($privacy->maskedMobile(null));
        $this->assertSame('****', $privacy->maskedMobile('1234'));
        $this->assertNotSame('13800001111', $privacy->maskedMobile('13800001111'));
    }

    public function test_encryption_uses_application_key(): void
    {
        $privacy = app(PersonPrivacy::class);

        $ciphertext = $privacy->encryptMobile('+8613800000000');

        $this->assertNotSame('+8613800000000', $ciphertext);
        $this->assertSame('+8613800000000', \Illuminate\Support\Facades\Crypt::decryptString($ciphertext));
    }
}
