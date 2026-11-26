<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\CorrectionService;
use App\Modules\Payroll\Services\ExportService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\ReconciliationService;
use App\Modules\Payroll\Services\ReopenService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Authorization\ResourceRef;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PayrollRunController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly \App\Shared\Time\Clock $clock,
        private readonly PayrollRunService $runs,
        private readonly CalculationService $calculation,
        private readonly ApprovalService $approval,
        private readonly CorrectionService $corrections,
        private readonly ReopenService $reopen,
        private readonly ExportService $exports,
        private readonly ReconciliationService $reconciliation,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $includeAmounts = $this->canSeeAmounts($principal);
        $page = max(1, (int) $request->query('page', '1'));
        $pageSize = min(100, max(1, (int) $request->query('page_size', '20')));

        $data = $this->runs->listRuns(
            (int) $principal->userId(),
            $this->businessDates->today()->value(),
            [
                'period' => is_string($request->query('period')) ? $request->query('period') : null,
                'status' => is_string($request->query('status')) ? $request->query('status') : null,
            ],
            $page,
            $pageSize,
        );

        if (! $includeAmounts) {
            $data['items'] = array_map(static function (array $item): array {
                unset($item['gross_cents'], $item['deductions_cents'], $item['net_cents'], $item['employer_cost_cents']);

                return $item;
            }, $data['items']);
        } else {
            // ADR-0024 requires P3 reads to be audited. Listing runs does not
            // call the row-level field projection by itself, so resolve the
            // field set per run; that path records field names (never values).
            foreach ($this->runs->runsByIds(array_map(static fn (array $item): int => (int) $item['id'], $data['items'])) as $run) {
                $this->moneyFields($principal, 'payroll.payslip.read', $run);
            }
        }

        return ApiResponse::ok($data);
    }

    public function show(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->findRun($id);

        return ApiResponse::ok($this->runs->runDetail($run, $this->canSeeAmounts($principal)));
    }

    public function employees(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->findRun($id);

        // A payroll.run.read holder may see the run summary but not amounts.
        // Amount visibility is decided by the payroll.payslip.read field
        // profile; a P3 grant reveals values, anything less omits the money
        // keys entirely rather than returning null.
        $fields = $this->moneyFields($principal, 'payroll.payslip.read', $run);

        $rows = $this->runs->employees($run, $fields);

        return ApiResponse::ok(ApiResponse::list($rows, 1, max(1, count($rows)), count($rows)));
    }

    private function canSeeAmounts(\App\Modules\IAM\Contracts\Principal $principal): bool
    {
        try {
            return $this->authorizer->permits(
                $principal,
                'payroll.payslip.read',
                $this->clock->now()->toDateString(),
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function moneyFields(\App\Modules\IAM\Contracts\Principal $principal, string $capability, \App\Modules\Payroll\Models\PayrollRun $run): FieldSet
    {
        try {
            return $this->authorizer->fields($principal, $capability, new ResourceRef(
                resourceType: 'payslip',
                id: (int) $run->id,
                row: [],
                payrollRunId: (int) $run->id,
            ));
        } catch (NotFoundOrForbiddenException) {
            return new FieldSet([
                'employment_id' => FieldProfile::P0_PUBLIC,
                'status' => FieldProfile::P0_PUBLIC,
                'failure_reason' => FieldProfile::P1_INTERNAL,
            ]);
        }
    }

    public function inputs(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->findRun($id);
        $fields = $this->moneyFields($principal, 'payroll.payslip.read', $run);
        $rows = $this->runs->inputSnapshots($run, $fields);

        return ApiResponse::ok(ApiResponse::list($rows, 1, max(1, count($rows)), count($rows)));
    }

    public function store(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'scheme_id' => ['required', 'integer', 'min:1'],
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'posting_period' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'pay_date' => ['nullable', 'date_format:Y-m-d'],
            'run_type' => ['nullable', 'string', 'in:REGULAR,OFF_CYCLE,BONUS'],
            'rule_version' => ['nullable', 'string', 'max:64'],
        ]);

        $run = $this->runs->createRun($principal->userId(), $data);

        return ApiResponse::created($this->runs->runDetail($run));
    }

    public function lockInputs(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->lockInputs($id, $principal->userId());

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function calculate(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->findRun($id);
        $result = $this->calculation->calculate($run, $principal->userId());

        return ApiResponse::ok([
            ...$result,
            'run' => $this->runs->runDetail($this->runs->findRun($id)),
        ]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        $run = $this->approval->review($id, $principal->userId(), (string) ($data['comment'] ?? ''));

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        $run = $this->approval->approve($id, $principal->userId(), (string) ($data['comment'] ?? ''));

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $run = $this->approval->publish($id, $principal->userId(), (string) ($data['reason'] ?? ''));

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function markPaid(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:255'],
            'manual_confirmation_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
        ]);
        $run = $this->approval->markPaid(
            $id,
            $principal->userId(),
            (string) ($data['reference'] ?? ''),
            (string) ($data['manual_confirmation_reason'] ?? ''),
        );

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function close(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->approval->close($id, $principal->userId());

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $run = $this->approval->cancel($id, $principal->userId(), (string) $data['reason']);

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'approver_user_ids' => ['required', 'array', 'min:2'],
            'approver_user_ids.*' => ['integer', 'min:1'],
        ]);
        $run = $this->reopen->reopen(
            $id,
            $principal->userId(),
            array_map('intval', (array) $data['approver_user_ids']),
            (string) $data['reason'],
        );

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function correction(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $run = $this->corrections->create(
            $id,
            $principal->userId(),
            (string) $data['period'],
            (string) $data['reason'],
        );

        return ApiResponse::created($this->runs->runDetail($run));
    }

    public function reconciliation(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->findRun($id);

        return ApiResponse::ok($this->reconciliation->reconcile((int) $run->id));
    }

    public function exportBank(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();

        return ApiResponse::ok($this->exports->bankCsv($id, $principal->userId()));
    }

    public function exportTax(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();

        return ApiResponse::ok($this->exports->taxCsv($id, $principal->userId()));
    }
}
