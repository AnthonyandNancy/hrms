<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\FileObjectReader;
use App\Modules\Platform\Models\FileObject;
use App\Shared\Exceptions\NotFoundException;
use Illuminate\Support\Facades\Storage;

final class EloquentFileObjectReader implements FileObjectReader
{
    public function contents(int $tenantId, int $fileObjectId): string
    {
        $file = FileObject::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($fileObjectId)
            ->first();

        if ($file === null) {
            throw new NotFoundException;
        }

        $contents = Storage::disk((string) config('filesystems.default', 'local'))->get((string) $file->storage_key);

        if (! is_string($contents)) {
            throw new NotFoundException;
        }

        return $contents;
    }

    public function exists(int $tenantId, int $fileObjectId): bool
    {
        return FileObject::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($fileObjectId)
            ->exists();
    }
}
