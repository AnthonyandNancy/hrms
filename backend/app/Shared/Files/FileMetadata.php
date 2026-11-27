<?php

declare(strict_types=1);

namespace App\Shared\Files;

final readonly class FileMetadata
{
    public function __construct(
        public int $id,
        public string $sha256,
        public string $mime,
        public int $sizeBytes,
        public string $retentionClass,
        public bool $legalHold,
        public string $originalName = '',
    ) {}
}
