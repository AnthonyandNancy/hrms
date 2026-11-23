<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Organization\Models\LegalEntity;
use App\Modules\People\Models\EmploymentTerms;
use Tests\Support\PeopleTestCase;

final class RequirementGapsTest extends PeopleTestCase
{
    public function test_assignment_outside_org_active_window_is_rejected(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'SHORT-ORG', null, 'Short', 'DEPARTMENT', '2026-01-01', '2026-06-01');
        $employment = $scenario->employment($scenario->person(), $entity, 'SHORT-EMP');

        $this->postJson("/v1/employments/{$employment->id}/assignments", [
            'org_unit_id' => $org->id,
            'assignment_type' => 'PRIMARY',
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-07-01',
        ])->assertUnprocessable();
    }

    public function test_terms_effective_to_is_clipped_to_employment_window(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'CLIP-EMP', ['valid_to' => '2026-12-31']);

        $response = $this->postJson("/v1/employments/{$employment->id}/terms", [
            'effective_from' => '2026-01-01',
            'effective_to' => '2027-12-31',
            'work_location' => 'Shanghai',
            'work_schedule' => 'STANDARD',
        ])->assertCreated();

        $this->assertSame('2026-12-31', $response->json('data.effective_to'));
    }

    public function test_manager_assignment_must_exist_and_be_valid(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'MGR-VALID');
        $employment = $scenario->employment($scenario->person(), $entity, 'MGR-VALID-EMP');

        $this->postJson("/v1/employments/{$employment->id}/assignments", [
            'org_unit_id' => $org->id,
            'assignment_type' => 'PRIMARY',
            'manager_assignment_id' => 999999,
            'valid_from' => '2026-01-01',
        ])->assertUnprocessable();
    }

    public function test_document_requires_same_tenant_file_object(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'DOC-TENANT');
        $fileId = $scenario->fileObject(tenantId: 2);

        $this->postJson("/v1/employments/{$employment->id}/documents", [
            'file_object_id' => $fileId,
            'category' => 'CONTRACT',
            'retention_class' => 'CONTRACT_10Y',
        ])->assertUnprocessable();
    }

    public function test_document_versions_increment_per_category(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'DOC-VER');

        $first = $this->postJson("/v1/employments/{$employment->id}/documents", [
            'file_object_id' => $scenario->fileObject(),
            'category' => 'CONTRACT',
            'retention_class' => 'CONTRACT_10Y',
        ])->assertCreated();
        $second = $this->postJson("/v1/employments/{$employment->id}/documents", [
            'file_object_id' => $scenario->fileObject(),
            'category' => 'CONTRACT',
            'retention_class' => 'CONTRACT_10Y',
        ])->assertCreated();

        $this->assertSame(1, $first->json('data.version'));
        $this->assertSame(2, $second->json('data.version'));
    }

    public function test_document_download_returns_no_storage_key_and_requires_both_capabilities(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'DOC-DL');
        $document = $scenario->document($employment, $scenario->fileObject());

        $response = $this->getJson("/v1/documents/{$document->id}/download")->assertOk();
        $this->assertTrue($response->json('data.download_requires_signed_url'));
        $this->assertNull($response->json('data.storage_key'));

        $this->authorizer->deniedCapabilities = ['people.contract.read'];
        $this->getJson("/v1/documents/{$document->id}/download")->assertForbidden();
    }

    public function test_legal_entity_timezone_and_currency_are_validated(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();

        $entity->timezone = 'Mars/Olympus';
        try {
            $entity->save();
            $this->fail('Invalid IANA timezone should be rejected.');
        } catch (\App\Shared\Exceptions\ValidationException $exception) {
            $this->assertArrayHasKey('timezone', $exception->details()['fields']);
        }

        $entity = LegalEntity::query()->findOrFail($entity->id);
        $entity->currency_code = 'CN';
        try {
            $entity->save();
            $this->fail('Invalid currency should be rejected.');
        } catch (\App\Shared\Exceptions\ValidationException $exception) {
            $this->assertArrayHasKey('currency_code', $exception->details()['fields']);
        }
    }

    public function test_terms_historical_row_is_not_overwritten_when_new_version_closes_it(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'TERMS-HIST');
        $scenario->terms($employment, 1, ['effective_from' => '2026-01-01', 'work_location' => 'Shanghai']);

        $this->postJson("/v1/employments/{$employment->id}/terms", [
            'effective_from' => '2026-06-01',
            'work_location' => 'Singapore',
            'work_schedule' => 'STANDARD',
        ])->assertCreated();

        $old = EmploymentTerms::query()->where('version', 1)->firstOrFail();
        $this->assertSame('Shanghai', $old->work_location);
        $this->assertSame('2026-06-01', $old->effective_to);
        $this->assertSame(2, EmploymentTerms::query()->count());
    }
}
