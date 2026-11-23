<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Person;
use Illuminate\Support\Facades\DB;
use Tests\Support\PeopleTestCase;

final class PersonDedupTest extends PeopleTestCase
{
    public function test_same_identity_type_and_hash_is_rejected(): void
    {
        $payload = [
            'legal_name' => 'Synthetic Duplicate',
            'id_type' => 'NATIONAL_ID',
            'id_number' => '110101199001011234',
        ];

        $this->postJson('/v1/persons', $payload)->assertCreated();

        $this->postJson('/v1/persons', $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'CONFLICT')
            ->assertJsonPath('data.reason', 'PERSON_IDENTITY_DUPLICATE');

        $this->assertSame(1, Person::query()->count());
    }

    public function test_identity_type_is_normalized_before_deduplication(): void
    {
        $this->postJson('/v1/persons', [
            'legal_name' => 'Canonical',
            'id_type' => 'NATIONAL_ID',
            'id_number' => 'CASE-SENSITIVE-1',
        ])->assertCreated();

        $this->postJson('/v1/persons', [
            'legal_name' => 'Lowercase',
            'id_type' => 'national_id',
            'id_number' => 'CASE-SENSITIVE-1',
        ])->assertConflict()->assertJsonPath('data.reason', 'PERSON_IDENTITY_DUPLICATE');
    }

    public function test_different_identity_type_is_allowed(): void
    {
        $this->postJson('/v1/persons', ['legal_name' => 'A', 'id_type' => 'NATIONAL_ID', 'id_number' => 'SAME-VALUE'])->assertCreated();
        $this->postJson('/v1/persons', ['legal_name' => 'B', 'id_type' => 'PASSPORT', 'id_number' => 'SAME-VALUE'])->assertCreated();

        $this->assertSame(2, Person::query()->count());
    }

    public function test_identity_and_mobile_are_encrypted_at_rest_and_never_serialized(): void
    {
        $response = $this->postJson('/v1/persons', [
            'legal_name' => 'Encrypted Person',
            'id_type' => 'NATIONAL_ID',
            'id_number' => '110101199001015678',
            'mobile' => '+8613812345678',
            'email' => 'encrypted@example.test',
        ])->assertCreated();

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('110101199001015678', $body);
        $this->assertStringNotContainsString('+8613812345678', $body);
        $this->assertArrayNotHasKey('id_number', $response->json('data'));
        $this->assertArrayNotHasKey('mobile_ciphertext', $response->json('data'));
        $this->assertArrayNotHasKey('id_number_ciphertext', $response->json('data'));

        $row = DB::table('persons')->first();
        $this->assertNotNull($row);
        $this->assertNotSame('110101199001015678', $row->id_number_ciphertext);
        $this->assertNotSame('+8613812345678', $row->mobile_ciphertext);
        $this->assertSame(64, strlen((string) $row->id_number_hash));

        $person = Person::query()->findOrFail((int) $row->id);
        $this->assertSame('110101199001015678', $person->id_number_ciphertext);
        $this->assertSame('+8613812345678', $person->mobile_ciphertext);
        $this->assertArrayNotHasKey('id_number', $person->toArray());
        $this->assertArrayNotHasKey('id_number_ciphertext', $person->toArray());
    }

    public function test_audit_and_outbox_payloads_never_contain_identity_plaintext(): void
    {
        $this->postJson('/v1/persons', [
            'legal_name' => 'Audited Person',
            'id_type' => 'NATIONAL_ID',
            'id_number' => 'PLAINTEXT-SECRET-999',
            'mobile' => '+8613999999999',
        ])->assertCreated();

        foreach (DB::table('audit_events')->pluck('after_json') as $payload) {
            $this->assertStringNotContainsString('PLAINTEXT-SECRET-999', (string) $payload);
            $this->assertStringNotContainsString('+8613999999999', (string) $payload);
        }
        foreach (DB::table('outbox_events')->pluck('payload_json') as $payload) {
            $this->assertStringNotContainsString('PLAINTEXT-SECRET-999', (string) $payload);
            $this->assertStringNotContainsString('+8613999999999', (string) $payload);
        }
    }
}
