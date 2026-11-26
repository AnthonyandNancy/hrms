<?php

declare(strict_types=1);

namespace App\Shared\Files;

/**
 * Read-only metadata port for private files. Business modules store only the
 * file id; storage keys and hashes stay inside the file service.
 */
interface FileMetadataReader
{
    public function metadata(int $fileObjectId): ?FileMetadata;
}
