<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

final readonly class CompiledPayrollRule
{
    /**
     * @param  array<string, mixed>  $ast
     * @param  list<string>  $variables
     */
    public function __construct(
        public array $ast,
        public string $hash,
        public int $nodeCount,
        public int $depth,
        public array $variables,
    ) {}
}
