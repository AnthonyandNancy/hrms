<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use InvalidArgumentException;

final class PayrollRuleCompileException extends InvalidArgumentException
{
    /**
     * @param  list<array{path:string,code:string,message:string}>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Payroll rule compilation failed: '.implode('; ', array_column($errors, 'message')));
    }
}
