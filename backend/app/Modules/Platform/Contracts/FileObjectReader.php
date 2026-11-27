<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

/**
 * Read port for private stored files. Business modules reference file objects
 * by id only; they never read Platform's file table directly.
 */
interface FileObjectReader
{
    /**
     * Return the stored bytes for a file object.
     *
     * @throws \App\Shared\Exceptions\NotFoundException when the file is not
     *                                                  visible to the caller
     */
    public function contents(int $fileObjectId): string;

    public function exists(int $fileObjectId): bool;
}
