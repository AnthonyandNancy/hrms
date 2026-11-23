<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Models\Document;
use App\Modules\People\Models\Employment;
use App\Modules\Platform\Models\FileObject;
use App\Modules\Platform\Services\AuditService;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Support\Facades\DB;

final class DocumentService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function open(int $tenantId, int $employmentId, array $input, ?int $actorUserId): Document
    {
        return DB::transaction(function () use ($tenantId, $employmentId, $input, $actorUserId): Document {
            $employmentExists = Employment::query()->where('tenant_id', $tenantId)->whereKey($employmentId)->exists();

            if (! $employmentExists) {
                throw new NotFoundOrForbiddenException;
            }

            $file = FileObject::query()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $input['file_object_id'])
                ->first();

            if ($file === null) {
                throw new ValidationException(['file_object_id' => ['The file object does not exist in this tenant.']]);
            }

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
                'file_object_id' => (int) $file->id,
                'version' => $version,
                'signed_at' => $input['signed_at'] ?? null,
                'sha256' => (string) $file->sha256,
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
        });
    }

    public function findForDownload(int $tenantId, int $documentId): ?Document
    {
        return Document::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($documentId)
            ->first();
    }
}
