<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Dto;

final class FilePlan
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly array $body,
    ) {}

    public function toJson(): string
    {
        return json_encode($this->body, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
