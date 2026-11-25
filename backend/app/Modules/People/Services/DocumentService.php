<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Models\Document;
use App\Modules\People\Models\Employment;
use App\Modules\Platform\Contracts\FileObjectReader;
use App\Modules\Platform\Contracts\PrivateFileStore;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Files\FileMetadataReader;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Employee documents (K3).
 *
 * The `documents` row is metadata; bytes live behind Platform's PrivateFileStore
 * contract. This service therefore never touches `file_objects` directly:
 *  - openBytes() stores bytes through Platform and records the metadata row;
 *  - listing() returns a ProjectionResult so the page gets the standard
 *    envelope and a stable source hash;
 *  - signedDownloadUrl() mints a short-lived, capability-guarded URL;
 *  - delete() refuses while a legal hold applies.
 */
final class DocumentService
{
    /** Signed download links are deliberately short-lived. */
    private const SIGNED_URL_TTL_MINUTES = 5;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly FileMetadataReader $files,
        private readonly PrivateFileStore $store,
        private readonly FileObjectReader $reader,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function open(int $tenantId, int $employmentId, array $input, ?int $actorUserId): Document
    {
        return DB::transaction(function () use ($tenantId, $employmentId, $input, $actorUserId): Document {
            if (! Employment::query()->where('tenant_id', $tenantId)->whereKey($employmentId)->exists()) {
                throw new NotFoundOrForbiddenException;
            }

            $file = $this->files->metadata($tenantId, (int) $input['file_object_id']);

            if ($file === null) {
                throw new ValidationException(['file_object_id' => ['The file object does not exist in this tenant.']]);
            }

            return $this->persist($tenantId, $employmentId, $input, $actorUserId, (int) $file->id, (string) $file->sha256);
        });
    }

    /**
     * Store raw bytes through Platform and record the document metadata row.
     *
     * @param  array<string, mixed>  $input
     */
    public function openBytes(
        int $tenantId,
        int $employmentId,
        array $input,
        string $contents,
        string $originalName,
        string $mime,
        ?int $actorUserId,
    ): Document {
        if ($contents === '') {
            throw new ValidationException(['file' => ['The uploaded file is empty.']]);
        }

        return DB::transaction(function () use ($tenantId, $employmentId, $input, $contents, $originalName, $mime, $actorUserId): Document {
            if (! Employment::query()->where('tenant_id', $tenantId)->whereKey($employmentId)->exists()) {
                throw new NotFoundOrForbiddenException;
            }

            $fileObjectId = $this->store->store(
                tenantId: $tenantId,
                contents: $contents,
                originalName: $originalName,
                mime: $mime,
                purpose: 'EMPLOYEE_DOCUMENT',
                ownerUserId: $actorUserId,
                metadata: ['category' => (string) ($input['category'] ?? 'OTHER')],
            );

            return $this->persist(
                $tenantId,
                $employmentId,
                $input,
                $actorUserId,
                $fileObjectId,
                hash('sha256', $contents),
            );
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function persist(
        int $tenantId,
        int $employmentId,
        array $input,
        ?int $actorUserId,
        int $fileObjectId,
        string $sha256,
    ): Document {
        $version = (int) Document::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', 'EMPLOYMENT')
            ->where('owner_id', $employmentId)
            ->where('category', (string) $input['category'])
            ->max('version') + 1;

        $document = Document::query()->create([
            'tenant_id' => $tenantId,
            'owner_type' => 'EMPLOYMENT',
            'owner_id' => $employmentId,
            'category' => (string) $input['category'],
            'file_object_id' => $fileObjectId,
            'version' => $version,
            'signed_at' => $input['signed_at'] ?? null,
            'sha256' => $sha256,
            'expires_at' => $input['expires_at'] ?? null,
            'retention_class' => (string) $input['retention_class'],
            'legal_hold' => (bool) ($input['legal_hold'] ?? false),
        ]);

        $this->audit->record(
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            action: 'people.document.attached',
            aggregateType: 'employment',
            aggregateId: $employmentId,
            after: ['document_id' => (int) $document->id, 'category' => (string) $document->category, 'version' => $version],
        );

        return $document;
    }

    /**
     * List documents for one employment as a standard projection.
     */
    public function listing(int $tenantId, int $employmentId, int $page, int $pageSize): ProjectionResult
    {
        $query = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', 'EMPLOYMENT')
            ->where('owner_id', $employmentId)
            ->orderByDesc('id');

        $total = (int) $query->clone()->count();

        $rows = $query
            ->forPage($page, $pageSize)
            ->get()
            ->map(fn (Document $document): array => $this->payload($tenantId, $document))
            ->all();

        return new ProjectionResult(
            items: $rows,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $this->clock->now()->toDateString(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: null,
        );
    }

    public function findForDownload(int $tenantId, int $documentId): ?Document
    {
        return Document::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($documentId)
            ->first();
    }

    /**
     * Mint a short-lived signed URL. The URL itself is the capability: it
     * expires quickly and is bound to the document id and tenant.
     */
    public function signedDownloadUrl(int $tenantId, Document $document, ?int $actorUserId = null): string
    {
        $url = URL::temporarySignedRoute(
            'documents.content',
            $this->clock->now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),
            ['document_id' => (int) $document->id, 'tenant_id' => $tenantId],
        );

        $this->audit->record(
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            action: 'people.document.download_signed',
            aggregateType: 'document',
            aggregateId: (int) $document->id,
            after: ['expires_in_minutes' => self::SIGNED_URL_TTL_MINUTES],
        );

        return $url;
    }

    public function contents(int $tenantId, Document $document): string
    {
        return $this->reader->contents($tenantId, (int) $document->getAttribute('file_object_id'));
    }

    public function delete(int $tenantId, Document $document, ?int $actorUserId): void
    {
        if ((bool) $document->legal_hold) {
            throw new ConflictException(
                'This document is under legal hold and cannot be deleted.',
                ['reason' => 'DOCUMENT_UNDER_LEGAL_HOLD'],
            );
        }

        DB::transaction(function () use ($tenantId, $document, $actorUserId): void {
            $fileObjectId = (int) $document->getAttribute('file_object_id');

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'people.document.deleted',
                aggregateType: 'document',
                aggregateId: (int) $document->id,
                before: ['category' => (string) $document->category, 'version' => (int) $document->version],
            );

            $document->delete();
            $this->store->delete($tenantId, $fileObjectId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $tenantId, Document $document): array
    {
        $file = $this->files->metadata($tenantId, (int) $document->getAttribute('file_object_id'));

        if ($file === null) {
            throw new NotFoundOrForbiddenException;
        }

        return [
            'id' => (int) $document->id,
            'employment_id' => (int) $document->owner_id,
            'category' => (string) $document->category,
            'version' => (int) $document->version,
            'original_name' => $file->originalName,
            'mime' => $file->mime,
            'size_bytes' => $file->sizeBytes,
            'sha256' => (string) $document->getAttribute('sha256'),
            'signed_at' => $document->getAttribute('signed_at') !== null
                ? \Carbon\CarbonImmutable::parse((string) $document->getAttribute('signed_at'))->toIso8601ZuluString()
                : null,
            'expires_at' => $document->getAttribute('expires_at'),
            'retention_class' => (string) $document->retention_class,
            'legal_hold' => (bool) $document->legal_hold,
            'created_at' => $document->created_at?->toIso8601ZuluString(),
        ];
    }
}
