<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\FileObjectReader;
use App\Modules\Platform\Models\FileObject;
use App\Shared\Exceptions\NotFoundException;
use Illuminate\Support\Facades\Storage;

final class EloquentFileObjectReader implements FileObjectReader
{
    public function contents(int $fileObjectId): string
    {
        $file = FileObject::query()
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

    public function exists(int $fileObjectId): bool
    {
        return FileObject::query()
            ->whereKey($fileObjectId)
            ->exists();
    }
}
