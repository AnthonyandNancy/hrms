<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Authorization\ResourceRef;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service payslip reads. Another employee's payslip returns 404, not 403,
 * to prevent resource enumeration.
 */
final class PayrollPayslipController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly PeopleDirectory $people,
        private readonly Authorizer $authorizer,
    ) {}

    public function mine(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $employmentId = $principal->employmentId()
            ?? $this->people->employmentForUser($principal->tenantId(), $principal->userId());

        if ($employmentId === null) {
            return ApiResponse::ok(ApiResponse::list([], 1, 20, 0));
        }

        $query = Payslip::query()
            ->where('tenant_id', $principal->tenantId())
            ->where('employment_id', $employmentId)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at');

        $period = $request->query('period');

        if (is_string($period) && $period !== '') {
            $runIds = PayrollRun::query()
                ->where('tenant_id', $principal->tenantId())
                ->where('period', $period)
                ->pluck('id')
                ->all();
            $query->whereIn('run_id', $runIds);
        }

        $payslips = $query->get();

        return ApiResponse::ok(ApiResponse::list(
            $payslips->map(fn (Payslip $payslip): array => $this->summary($payslip, $this->fieldsFor($payslip)))->all(),
            1,
            20,
            $payslips->count(),
        ));
    }

    public function show(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $payslip = Payslip::query()
            ->where('tenant_id', $principal->tenantId())
            ->whereKey($id)
            ->first();

        if ($payslip === null || $payslip->published_at === null) {
            throw new NotFoundOrForbiddenException;
        }

        $employmentIds = array_values(array_unique(array_filter([
            $principal->employmentId(),
            $this->people->employmentForUser($principal->tenantId(), $principal->userId()),
        ])));

        if (! in_array((int) $payslip->employment_id, array_map('intval', $employmentIds), true)) {
            // Cross-employee access is indistinguishable from a missing row.
            throw new NotFoundOrForbiddenException;
        }

        $run = PayrollRun::query()
            ->where('tenant_id', $principal->tenantId())
            ->whereKey((int) $payslip->run_id)
            ->first();

        return ApiResponse::ok([
            ...$this->summary($payslip, $this->fieldsFor($payslip)),
            'period' => $run !== null ? (string) $run->period : null,
            'lines' => PayslipLine::query()
                ->where('payslip_id', $payslip->id)
                ->orderBy('sort_order')
                ->get()
                ->map(static fn (PayslipLine $line): array => [
                    'item_code' => (string) $line->item_code,
                    'category' => (string) $line->category,
                    'quantity_scaled' => (int) $line->quantity_scaled,
                    'unit' => (string) $line->unit,
                    'rate_scaled' => (int) $line->rate_scaled,
                    'amount_cents' => (int) $line->amount_cents,
                    'taxable' => (bool) $line->taxable,
                    'source_type' => (string) $line->source_type,
                    'source_id' => (string) $line->source_id,
                ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Payslip $payslip, FieldSet $fields): array
    {
        return [
            'id' => (int) $payslip->id,
            'run_id' => (int) $payslip->run_id,
            'employment_id' => (int) $payslip->employment_id,
            'version' => (int) $payslip->version,
            ...($fields->isVisible('gross') ? ['gross_cents' => (int) $payslip->gross_cents] : []),
            ...($fields->isVisible('deductions') ? ['deductions_cents' => (int) $payslip->deductions_cents] : []),
            ...($fields->isVisible('net') ? ['net_cents' => (int) $payslip->net_cents] : []),
            ...($fields->isVisible('employer_cost') ? ['employer_cost_cents' => (int) $payslip->employer_cost_cents] : []),
            'published_at' => $payslip->published_at?->toIso8601ZuluString(),
        ];
    }

    private function fieldsFor(Payslip $payslip): FieldSet
    {
        $principal = $this->principals->resolve();

        try {
            return $this->authorizer->fields($principal, 'payroll.payslip.read.self', new ResourceRef(
                resourceType: 'payslip',
                id: (int) $payslip->id,
                row: [],
                tenantId: (int) $payslip->tenant_id,
                employmentId: (int) $payslip->employment_id,
                payrollRunId: (int) $payslip->run_id,
            ));
        } catch (NotFoundOrForbiddenException) {
            return new FieldSet([
                'gross' => FieldProfile::P3_RESTRICTED,
                'deductions' => FieldProfile::P3_RESTRICTED,
                'net' => FieldProfile::P3_RESTRICTED,
                'employer_cost' => FieldProfile::P3_RESTRICTED,
            ]);
        }
    }
}
