<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use InvalidArgumentException;

/**
 * Mandatory provenance for every payslip line. Source ids are stored as
 * strings so contracts, integer-keyed facts, and external snapshots all share
 * the same traceable representation.
 */
final readonly class PayrollLineSource
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (trim($this->type) === '' || preg_match('/[\x00-\x1F\x7F]/', $this->type) === 1) {
            throw new InvalidArgumentException('Payslip line source type is mandatory and must not contain control characters.');
        }

        if (trim($this->id) === '' || preg_match('/[\x00-\x1F\x7F]/', $this->id) === 1) {
            throw new InvalidArgumentException('Payslip line source id is mandatory and must not contain control characters.');
        }
    }
}
