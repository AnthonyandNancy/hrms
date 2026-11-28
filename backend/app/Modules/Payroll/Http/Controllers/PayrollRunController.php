<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\IAM\Contracts\AllowedActionResolver;
use App\Modules\Payroll\Enums\PayrollRunStatus;
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
        private readonly PayrollRunService $runs,
        private readonly CalculationService $calculation,
        private readonly ApprovalService $approval,
        private readonly CorrectionService $corrections,
        private readonly ReopenService $reopen,
        private readonly ExportService $exports,
        private readonly ReconciliationService $reconciliation,
        private readonly CompanyBusinessDate $businessDates,
        private readonly AllowedActionResolver $allowedActions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
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

        // Money visibility is decided PER RUN, never once per request: holding
        // P3 on run A must not reveal run B's amounts. Resolving the field set
        // per run also gives ADR-0024 its audited P3 read.
        // ADR-0024: every row's payslip field set is resolved (audited, field
        // names only), and the anchored check then decides whether THIS run's
        // amounts may be returned.
        $amountsByRunId = [];
        foreach ($data['items'] as $item) {
            $runId = (int) $item['id'];
            $fields = $this->moneyFieldsForRunId($principal, 'payroll.payslip.read', $runId);
            // The field set already applies the binding's profile ceiling (a P2
            // payslip grant hides P3 money), so visibility comes from it rather
            // than from capability presence alone.
            $amountsByRunId[$runId] = $fields->isVisible('gross') && $fields->isVisible('net');
        }

        $data['items'] = array_map(static function (array $item) use ($amountsByRunId): array {
            if (($amountsByRunId[(int) $item['id']] ?? false) === true) {
                return $item;
            }

            unset($item['gross_cents'], $item['deductions_cents'], $item['net_cents'], $item['employer_cost_cents']);

            return $item;
        }, $data['items']);

        return ApiResponse::ok($data);
    }

    public function show(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->findRun($id);

        // Same per-run rule as the list: the run's own field set decides, which
        // enforces both the anchor and the profile ceiling.
        $fields = $this->moneyFields($principal, 'payroll.payslip.read', $run);
        $includeAmounts = $fields->isVisible('gross') && $fields->isVisible('net');
        $status = $run->status instanceof PayrollRunStatus
            ? $run->status->value
            : (string) $run->status;
        $businessDate = $this->businessDates->today()->value();

        return ApiResponse::ok([
            ...$this->runs->runDetail($run, $includeAmounts),
            'state' => $status,
            'payslip_status' => (string) $run->payslip_status,
            'disbursement_status' => (string) $run->disbursement_status,
            'version' => (int) $run->snapshot_version,
            'source_version' => $run->input_hash,
            'allowed_actions' => $this->allowedActions->for(
                $principal,
                'payroll_run',
                (int) $run->id,
                ['status' => $status, 'payslip_status' => (string) $run->payslip_status, 'disbursement_status' => (string) $run->disbursement_status],
                $businessDate,
            ),
        ]);
    }

    public function assignMember(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'member_role' => ['required', 'string', 'in:REVIEWER,APPROVER,PUBLISHER,DISBURSER'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $member = $this->runs->assignMember(
            runId: $id,
            actorUserId: (int) $principal->userId(),
            targetUserId: (int) $data['user_id'],
            memberRole: (string) $data['member_role'],
            reason: (string) $data['reason'],
        );

        return ApiResponse::created($member);
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

    /**
     * Whether this run's amounts may be returned to this principal. Used by
     * every response that embeds a run summary, so a mutation response cannot
     * leak amounts that a read would hide (V3 NEW-P2).
     */
    /**
     * Drop run-level money keys from an embedded payload when the caller may not
     * see amounts on this run.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function omitRunAmounts(array $payload, bool $visible): array
    {
        if ($visible) {
            return $payload;
        }

        foreach (['gross_cents', 'deductions_cents', 'net_cents', 'employer_cost_cents'] as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    private function amountsVisibleFor(\App\Modules\IAM\Contracts\Principal $principal, \App\Modules\Payroll\Models\PayrollRun $run): bool
    {
        $fields = $this->moneyFields($principal, 'payroll.payslip.read', $run);

        return $fields->isVisible('gross') && $fields->isVisible('net');
    }

    private function moneyFieldsForRunId(
        \App\Modules\IAM\Contracts\Principal $principal,
        string $capability,
        int $runId,
    ): FieldSet {
        try {
            return $this->authorizer->fields($principal, $capability, new ResourceRef(
                resourceType: 'payslip',
                id: $runId,
                row: [],
                payrollRunId: $runId,
            ));
        } catch (NotFoundOrForbiddenException) {
            return new FieldSet([
                'employment_id' => FieldProfile::P0_PUBLIC,
                'status' => FieldProfile::P0_PUBLIC,
                'failure_reason' => FieldProfile::P1_INTERNAL,
            ]);
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

        return ApiResponse::created($this->runs->runDetail($run, $this->amountsVisibleFor($principal, $run)));
    }

    public function lockInputs(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->lockInputs($id, $principal->userId());

        return ApiResponse::ok($this->runs->runDetail($run, $this->amountsVisibleFor($principal, $run)));
    }

    public function calculate(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->runs->findRun($id);
        $result = $this->calculation->calculate($run, $principal->userId());
        $refreshed = $this->runs->findRun($id);

        // The calculation result carries run totals; strip them unless this
        // principal may see amounts on THIS run (same rule as every other
        // response that embeds a run).
        $visible = $this->amountsVisibleFor($principal, $refreshed);

        return ApiResponse::ok([
            ...$this->omitRunAmounts($result, $visible),
            'run' => $this->runs->runDetail($refreshed, $visible),
        ]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        $run = $this->approval->review($id, $principal->userId(), (string) ($data['comment'] ?? ''));

        return ApiResponse::ok($this->runs->runDetail($run, $this->amountsVisibleFor($principal, $run)));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        $run = $this->approval->approve($id, $principal->userId(), (string) ($data['comment'] ?? ''));

        return ApiResponse::ok($this->runs->runDetail($run, $this->amountsVisibleFor($principal, $run)));
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $run = $this->approval->publish($id, $principal->userId(), (string) ($data['reason'] ?? ''));

        return ApiResponse::ok($this->runs->runDetail($run, $this->amountsVisibleFor($principal, $run)));
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

        return ApiResponse::ok($this->runs->runDetail($run, $this->amountsVisibleFor($principal, $run)));
    }

    public function close(int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $run = $this->approval->close($id, $principal->userId());

        return ApiResponse::ok($this->runs->runDetail($run, $this->amountsVisibleFor($principal, $run)));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $run = $this->approval->cancel($id, $principal->userId(), (string) $data['reason']);

        return ApiResponse::ok($this->runs->runDetail($run, $this->amountsVisibleFor($principal, $run)));
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

        return ApiResponse::ok($this->runs->runDetail($run, $this->amountsVisibleFor($principal, $run)));
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

        return ApiResponse::created($this->runs->runDetail($run, $this->amountsVisibleFor($principal, $run)));
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
