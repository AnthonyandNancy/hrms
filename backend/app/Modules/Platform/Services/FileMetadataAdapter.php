<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\FileObject;
use App\Shared\Files\FileMetadata;
use App\Shared\Files\FileMetadataReader;

final class FileMetadataAdapter implements FileMetadataReader
{
    public function metadata(int $tenantId, int $fileObjectId): ?FileMetadata
    {
        $file = FileObject::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($fileObjectId)
            ->first();

        if ($file === null) {
            return null;
        }

        return new FileMetadata(
            id: (int) $file->id,
            tenantId: (int) $file->tenant_id,
            sha256: (string) $file->sha256,
            mime: (string) $file->mime,
            sizeBytes: (int) $file->size_bytes,
            retentionClass: (string) $file->retention_class,
            legalHold: (bool) $file->legal_hold,
            originalName: (string) $file->original_name,
        );
    }
}
