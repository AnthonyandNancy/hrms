<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

/**
 * Private file writer for business modules that own a file reference but must
 * never touch Platform's file_objects table or storage keys directly.
 */
interface PrivateFileStore
{
    /**
     * Store bytes on the private disk and return the Platform file-object id.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function store(
        string $contents,
        string $originalName,
        string $mime,
        string $purpose,
        ?int $ownerUserId = null,
        array $metadata = [],
    ): int;

    public function delete(int $fileObjectId): bool;
}
