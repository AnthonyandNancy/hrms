<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\PrivateFileStore;
use App\Modules\Platform\Models\FileObject;
use App\Shared\Exceptions\NotFoundException;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class LocalPrivateFileStore implements PrivateFileStore
{
    public function store(
        int $tenantId,
        string $contents,
        string $originalName,
        string $mime,
        string $purpose,
        ?int $ownerUserId = null,
        array $metadata = [],
    ): int {
        $disk = Storage::disk(config('filesystems.default', 'local'));
        $key = sprintf('private/reporting/%d/%s-%s', $tenantId, bin2hex(random_bytes(8)), preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName) ?? 'export.csv');

        if (! $disk->put($key, $contents)) {
            throw new RuntimeException('Private file storage rejected the write.');
        }

        $file = FileObject::query()->create([
            'tenant_id' => $tenantId,
            'storage_key' => $key,
            'sha256' => hash('sha256', $contents),
            'original_name' => $originalName,
            'mime' => $mime,
            'size_bytes' => strlen($contents),
            'owner_user_id' => $ownerUserId,
            'purpose' => $purpose,
            'retention_class' => 'REPORT_EXPORT_24H',
            'legal_hold' => false,
            'created_at' => now('UTC'),
        ]);

        return (int) $file->id;
    }

    public function delete(int $tenantId, int $fileObjectId): bool
    {
        $file = FileObject::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($fileObjectId)
            ->first();

        if ($file === null) {
            throw new NotFoundException;
        }

        Storage::disk(config('filesystems.default', 'local'))->delete((string) $file->storage_key);
        $file->delete();

        return true;
    }
}
