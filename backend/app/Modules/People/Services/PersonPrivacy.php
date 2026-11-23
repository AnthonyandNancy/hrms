<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Models\Person;
use Illuminate\Support\Facades\Crypt;

/**
 * Single place where person PII is normalized, hashed and encrypted. Plaintext
 * identity/mobile values are never merged into API or audit payloads.
 */
final class PersonPrivacy
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function encryptIdentity(array $attributes): string
    {
        return Crypt::encryptString((string) $attributes['id_number']);
    }

    public function hashIdentity(string $idType, string $idNumber): string
    {
        return hash_hmac('sha256', mb_strtoupper($idType).'|'.trim($idNumber), (string) config('hrms.pii_hmac_key'));
    }

    public function encryptMobile(string $mobile): string
    {
        return Crypt::encryptString($mobile);
    }

    /**
     * P1 callers receive a masked mobile number. P2/P3 callers receive no
     * additional plaintext from this projection.
     */
    public function maskedMobile(?string $mobile): ?string
    {
        if ($mobile === null || $mobile === '') {
            return null;
        }

        $length = mb_strlen($mobile);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', max(0, $length - 4)).mb_substr($mobile, -4);
    }

    /**
     * Project a person for a given field profile. Identity numbers are always
     * omitted, and P1 callers receive only a masked mobile number.
     *
     * @return array<string, mixed>
     */
    public function project(Person $person, string $profile = 'P1_INTERNAL'): array
    {
        $mobile = $person->getAttribute('mobile_ciphertext');
        $row = [
            'id' => (int) $person->id,
            'tenant_id' => (int) $person->tenant_id,
            'legal_name' => (string) $person->legal_name,
            'preferred_name' => $person->preferred_name !== null ? (string) $person->preferred_name : null,
            'birth_date' => $person->getAttribute('birth_date') instanceof \DateTimeInterface
                ? $person->getAttribute('birth_date')->format('Y-m-d')
                : ($person->getAttribute('birth_date') !== null ? (string) $person->getAttribute('birth_date') : null),
            'gender' => $person->gender !== null ? (string) $person->gender : null,
            'nationality' => $person->nationality !== null ? (string) $person->nationality : null,
            'email' => $person->email !== null ? (string) $person->email : null,
            'mobile' => $this->maskedMobile(is_string($mobile) ? $mobile : null),
        ];

        if ($profile === 'P2_SENSITIVE') {
            $row['mobile'] = $this->maskedMobile(is_string($mobile) ? $mobile : null);
        }

        if ($profile === 'P3_RESTRICTED') {
            $row['mobile'] = null;
        }

        return $row;
    }
}
