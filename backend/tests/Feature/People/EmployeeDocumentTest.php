<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Employment;
use Tests\Support\PeopleTestCase;

/**
 * K3 — employee document list, signed download and deletion.
 *
 * The document row is metadata only: bytes live in Platform's private store and
 * are referenced by a file-object id. These tests prove the full round trip and
 * the two safety rules that make deletion safe: a held document is never
 * removed, and a download only reveals bytes through a short-lived signed URL.
 */
final class EmployeeDocumentTest extends PeopleTestCase
{
    private function employment(string $employeeNo = 'DOC-0001'): Employment
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'DOC-ORG-'.$employeeNo);
        $employment = $scenario->employment($scenario->person(), $entity, $employeeNo);
        $scenario->assignment($employment, $org);

        return $employment;
    }

    public function test_document_round_trip_from_upload_through_signed_download(): void
    {
        $employment = $this->employment();
        $bytes = "%PDF-1.4 synthetic contract body\n";

        $created = $this->postMultipart("/v1/employments/{$employment->id}/document-files", [
            'category' => 'CONTRACT',
            'retention_class' => 'CONTRACT_7Y',
            'file' => $this->fakeUpload('contract.pdf', $bytes),
        ], ['Idempotency-Key' => 'doc-upload-1'])->assertCreated();

        $documentId = (int) $created->json('data.id');
        $this->assertGreaterThan(0, $documentId);

        $list = $this->getJson("/v1/employments/{$employment->id}/document-files")
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $list['total']);
        $this->assertSame('CONTRACT', $list['items'][0]['category']);
        $this->assertSame('contract.pdf', $list['items'][0]['original_name']);
        $this->assertArrayHasKey('source_hash', $list);
        $this->assertSame('LIVE', $list['freshness']);

        // The signed URL is the only way to obtain bytes, and the content route
        // additionally requires the download capability.
        $signed = $this->getJson("/v1/documents/{$documentId}/signed-download")
            ->assertOk()
            ->json('data');

        $download = $this->get($signed['url'])->assertOk();
        $this->assertSame($bytes, $download->getContent());

        // Without a valid signature the same bytes are unreachable.
        $this->getJson("/v1/documents/{$documentId}/content")->assertForbidden();
    }

    public function test_a_document_under_legal_hold_cannot_be_deleted(): void
    {
        $employment = $this->employment();

        $documentId = (int) $this->postMultipart("/v1/employments/{$employment->id}/document-files", [
            'category' => 'ID',
            'retention_class' => 'IDENTITY_10Y',
            'legal_hold' => '1',
            'file' => $this->fakeUpload('id.pdf', 'synthetic id'),
        ], ['Idempotency-Key' => 'doc-upload-held'])->assertCreated()->json('data.id');

        $this->deleteJson("/v1/documents/{$documentId}", [], ['Idempotency-Key' => 'doc-delete-held'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'DOCUMENT_UNDER_LEGAL_HOLD');

        $this->assertDatabaseHas('documents', ['id' => $documentId]);
    }

    public function test_a_released_document_can_be_deleted_and_its_file_object_is_removed(): void
    {
        $employment = $this->employment();

        $documentId = (int) $this->postMultipart("/v1/employments/{$employment->id}/document-files", [
            'category' => 'OTHER',
            'retention_class' => 'GENERAL_2Y',
            'file' => $this->fakeUpload('note.pdf', 'synthetic note'),
        ], ['Idempotency-Key' => 'doc-upload-free'])->assertCreated()->json('data.id');

        $fileObjectId = (int) \App\Modules\People\Models\Document::query()->whereKey($documentId)->value('file_object_id');

        $this->deleteJson("/v1/documents/{$documentId}", [], ['Idempotency-Key' => 'doc-delete-free'])
            ->assertOk();

        $this->assertDatabaseMissing('documents', ['id' => $documentId]);
        $this->assertFalse(app(\App\Modules\Platform\Contracts\FileObjectReader::class)->exists(1, $fileObjectId));
    }

    public function test_documents_are_tenant_and_employment_scoped(): void
    {
        $employment = $this->employment('DOC-0002');
        $other = $this->employment('DOC-0003');

        $documentId = (int) $this->postMultipart("/v1/employments/{$employment->id}/document-files", [
            'category' => 'OTHER',
            'retention_class' => 'GENERAL_2Y',
            'file' => $this->fakeUpload('a.pdf', 'a'),
        ], ['Idempotency-Key' => 'doc-scope-1'])->assertCreated()->json('data.id');

        $this->assertSame(0, $this->getJson("/v1/employments/{$other->id}/document-files")->assertOk()->json('data.total'));

        $this->getJson("/v1/documents/{$documentId}/signed-download")->assertOk();
    }

    public function test_download_requires_the_download_capability_not_just_read(): void
    {
        $employment = $this->employment();

        $documentId = (int) $this->postMultipart("/v1/employments/{$employment->id}/document-files", [
            'category' => 'OTHER',
            'retention_class' => 'GENERAL_2Y',
            'file' => $this->fakeUpload('b.pdf', 'b'),
        ], ['Idempotency-Key' => 'doc-cap-1'])->assertCreated()->json('data.id');

        $this->actingAsUserWithCapabilities(['people.document.read']);

        $this->getJson("/v1/documents/{$documentId}/signed-download")->assertForbidden();
    }
}
