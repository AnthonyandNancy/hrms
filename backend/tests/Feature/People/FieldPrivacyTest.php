<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Person;
use App\Modules\People\Services\PersonPrivacy;
use Illuminate\Support\Facades\DB;
use Tests\Support\PeopleTestCase;

final class FieldPrivacyTest extends PeopleTestCase
{
    public function test_identity_number_never_appears_in_profile_or_timeline_responses(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('PRIV');
        $person = $scenario->person(['id_number' => 'SECRET-IDENTITY-123']);
        $employment = $scenario->employment($person, 'PRIV-1');
        $scenario->assignment($employment, $org);

        $profile = $this->getJson("/v1/employees/{$employment->id}?as_of=2026-11-23")->assertOk();
        $timeline = $this->getJson("/v1/employments/{$employment->id}/timeline")->assertOk();

        foreach ([$profile->getContent(), $timeline->getContent()] as $body) {
            $this->assertIsString($body);
            $this->assertStringNotContainsString('SECRET-IDENTITY-123', $body);
            $this->assertStringNotContainsString('id_number', $body);
            $this->assertStringNotContainsString('ciphertext', $body);
        }
    }

    public function test_mobile_is_masked_for_p1_profile(): void
    {
        $person = Person::query()->create([
            'legal_name' => 'Masked Person',
            'id_type' => null,
            'id_number_ciphertext' => null,
            'id_number_hash' => null,
            'birth_date' => '1990-01-01',
            'gender' => null,
            'nationality' => 'CN',
            'mobile_ciphertext' => '+8613812345678',
            'email' => null,
        ]);

        $projection = app(PersonPrivacy::class)->project($person, 'P1_INTERNAL');

        $this->assertSame('**********5678', $projection['mobile']);
        $this->assertArrayNotHasKey('id_number', $projection);
        $this->assertArrayNotHasKey('mobile_ciphertext', $projection);
    }

    public function test_document_download_requires_capability(): void
    {
        $scenario = $this->scenario();
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'DOC-1');
        $document = $scenario->document($employment, $scenario->fileObject());

        $this->authorizer->deniedCapabilities = ['people.document.download'];
        $this->getJson("/v1/documents/{$document->id}/download")->assertForbidden();
    }

    public function test_document_metadata_hides_storage_key_and_hash(): void
    {
        $scenario = $this->scenario();
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'DOC-2');
        $fileId = $scenario->fileObject();

        $response = $this->postJson("/v1/employments/{$employment->id}/documents", [
            'file_object_id' => $fileId,
            'category' => 'CONTRACT',
            'retention_class' => 'CONTRACT_10Y',
        ])->assertCreated();

        $this->assertArrayNotHasKey('sha256', $response->json('data'));
        $this->assertArrayNotHasKey('storage_key', $response->json('data'));
        $this->assertDatabaseHas('documents', ['id' => $response->json('data.id'), 'file_object_id' => $fileId]);
    }

    public function test_sensitive_access_material_is_not_persisted_in_plaintext(): void
    {
        $this->postJson('/v1/persons', [
            'legal_name' => 'Log Safe',
            'id_type' => 'NATIONAL_ID',
            'id_number' => 'LOG-SECRET-1',
            'mobile' => '+8613800001111',
        ])->assertCreated();

        $row = DB::table('persons')->where('legal_name', 'Log Safe')->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('LOG-SECRET-1', (string) $row->id_number_ciphertext);
        $this->assertStringNotContainsString('+8613800001111', (string) $row->mobile_ciphertext);
    }
}
