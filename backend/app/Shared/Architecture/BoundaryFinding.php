<?php

declare(strict_types=1);

namespace App\Shared\Architecture;

/**
 * One cross-module reference or table-access breach with file provenance.
 */
final readonly class BoundaryFinding
{
    public function __construct(
        public string $type,
        public string $file,
        public int $line,
        public string $module,
        public string $reference,
        public string $message,
    ) {}

    /**
     * @return array{type: string, file: string, line: int, module: string, reference: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'file' => $this->file,
            'line' => $this->line,
            'module' => $this->module,
            'reference' => $this->reference,
            'message' => $this->message,
        ];
    }
}
