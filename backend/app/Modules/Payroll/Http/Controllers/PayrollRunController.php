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
use App\Shared\Authorization\PrincipalResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PayrollRunController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly PayrollRunService $runs,
        private readonly CalculationService $calculation,
        private readonly ApprovalService $approval,
        private readonly CorrectionService $corrections,
        private readonly ReopenService $reopen,
        private readonly ExportService $exports,
        private readonly ReconciliationService $reconciliation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $page = max(1, (int) $request->query('page', '1'));
        $pageSize = min(100, max(1, (int) $request->query('page_size', '20')));

        $data = $this->runs->listRuns(
            $principal->tenantId(),
            [
                'period' => is_string($request->query('period')) ? $request->query('period') : null,
                'status' => is_string($request->query('status')) ? $request->query('status') : null,
                'legal_entity_id' => $request->query('legal_entity_id'),
            ],
            $page,
            $pageSize,
        );

        return ApiResponse::ok($data);
    }

    public function show(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->findRun($principal->tenantId(), $id);

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function employees(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->findRun($principal->tenantId(), $id);
        $rows = $this->runs->employees($run);

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

        $run = $this->runs->createRun($principal->tenantId(), $principal->userId(), $data);

        return ApiResponse::created($this->runs->runDetail($run));
    }

    public function lockInputs(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->lockInputs($principal->tenantId(), $id, $principal->userId());

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function calculate(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->findRun($principal->tenantId(), $id);
        $result = $this->calculation->calculate($run, $principal->userId());

        return ApiResponse::ok([
            ...$result,
            'run' => $this->runs->runDetail($this->runs->findRun($principal->tenantId(), $id)),
        ]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        $run = $this->approval->review($principal->tenantId(), $id, $principal->userId(), (string) ($data['comment'] ?? ''));

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        $run = $this->approval->approve($principal->tenantId(), $id, $principal->userId(), (string) ($data['comment'] ?? ''));

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $run = $this->approval->publish($principal->tenantId(), $id, $principal->userId(), (string) ($data['reason'] ?? ''));

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function markPaid(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:255'],
            'manual_confirmation_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
        ]);
        $run = $this->approval->markPaid($principal->tenantId(), $id, $principal->userId(), (string) ($data['reference'] ?? ''));

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function close(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->approval->close($principal->tenantId(), $id, $principal->userId());

        return ApiResponse::ok($this->runs->runDetail($run));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $run = $this->approval->cancel($principal->tenantId(), $id, $principal->userId(), (string) $data['reason']);

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
            $principal->tenantId(),
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
            $principal->tenantId(),
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
        $run = $this->runs->findRun($principal->tenantId(), $id);

        return ApiResponse::ok($this->reconciliation->reconcile($principal->tenantId(), (int) $run->id));
    }

    public function exportBank(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();

        return ApiResponse::ok($this->exports->bankCsv($principal->tenantId(), $id, $principal->userId()));
    }

    public function exportTax(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();

        return ApiResponse::ok($this->exports->taxCsv($principal->tenantId(), $id, $principal->userId()));
    }
}
