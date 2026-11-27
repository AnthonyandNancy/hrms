<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Person;
use App\Modules\People\Services\PersonPrivacy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleTestCase;

final class FieldProfileProjectionTest extends PeopleTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function profileProvider(): array
    {
        return [
            'P0' => ['P0_PUBLIC'],
            'P1' => ['P1_INTERNAL'],
            'P2' => ['P2_SENSITIVE'],
            'P3' => ['P3_RESTRICTED'],
        ];
    }

    #[DataProvider('profileProvider')]
    public function test_identity_ciphertext_and_plaintext_are_absent_from_every_profile(string $profile): void
    {
        $person = Person::query()->create([
            'legal_name' => 'Projection Person',
            'id_type' => 'NATIONAL_ID',
            'id_number_ciphertext' => 'SECRET-IDENTITY',
            'id_number_hash' => str_repeat('a', 64),
            'birth_date' => '1990-01-01',
            'gender' => 'FEMALE',
            'nationality' => 'CN',
            'mobile_ciphertext' => '+8613812345678',
            'email' => 'projection@example.test',
        ]);

        $projection = app(PersonPrivacy::class)->project($person, $profile);
        $encoded = (string) json_encode($projection);

        $this->assertStringNotContainsString('SECRET-IDENTITY', $encoded);
        $this->assertStringNotContainsString('+8613812345678', $encoded);
        $this->assertArrayNotHasKey('id_number', $projection);
        $this->assertArrayNotHasKey('id_number_ciphertext', $projection);
        $this->assertArrayNotHasKey('id_number_hash', $projection);
    }
}
