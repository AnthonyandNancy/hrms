<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Contracts\CompensationVersionDirectory;
use App\Modules\Payroll\Models\EmployeeCompensation;

/**
 * Read-only effective compensation resolver. DRAFT/PENDING/SUPERSEDED rows
 * are never valid payroll inputs; activation is an explicit Payroll command.
 */
final class EloquentCompensationVersionDirectory implements CompensationVersionDirectory
{
    public function effectiveForEmployment(int $employmentId, string $asOf): ?array
    {
        $row = EmployeeCompensation::query()
            ->where('employment_id', $employmentId)
            ->where('status', 'ACTIVE')
            ->where('effective_from', '<=', $asOf)
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'compensation_id' => (int) $row->id,
            'employment_id' => (int) $row->employment_id,
            'contract_version_id' => $row->contract_version_id !== null ? (int) $row->contract_version_id : null,
            'effective_from' => (string) $row->effective_from,
            'effective_to' => $row->effective_to !== null ? (string) $row->effective_to : null,
            'status' => (string) $row->status,
            'base_salary_cents' => (int) $row->base_salary_cents,
            'currency' => (string) $row->currency,
            'salary_scheme_code' => $row->salary_scheme_code !== null ? (string) $row->salary_scheme_code : null,
            'payroll_scheme_id' => $row->payroll_scheme_id !== null ? (int) $row->payroll_scheme_id : null,
            'payroll_scheme_version' => $row->payroll_scheme_version !== null ? (int) $row->payroll_scheme_version : null,
            'parameter_overrides' => (array) ($row->parameter_overrides_json ?? []),
        ];
    }
}
