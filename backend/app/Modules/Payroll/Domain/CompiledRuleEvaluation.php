<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

final readonly class CompiledRuleEvaluation
{
    /**
     * @param  list<array{path:string,type:string,value:string}>  $trace
     */
    public function __construct(
        public string $value,
        public array $trace,
    ) {}
}
