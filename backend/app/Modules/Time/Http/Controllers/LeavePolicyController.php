<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Models\LeavePolicyVersion;
use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Services\LeavePolicyService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Effective-dated leave policy versions. All writes use CommandRunner with
 * an Idempotency-Key; version allocation and overlap checks happen inside the
 * service transaction while the leave type row is locked.
 */
final class LeavePolicyController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly LeavePolicyService $policies,
        private readonly CommandRunner $commands,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->scope->actor($request);
        $data = $request->validate([
            'leave_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', 'string', 'in:PUBLISHED,RETIRED,DRAFT'],
        ]);

        $query = LeavePolicyVersion::query()
            ->orderBy('leave_type_id')
            ->orderByDesc('version');

        if (isset($data['leave_type_id'])) {
            $query->where('leave_type_id', (int) $data['leave_type_id']);
        }

        if (isset($data['status'])) {
            $query->where('status', strtoupper((string) $data['status']));
        }

        $types = LeaveType::query()->get()->keyBy('id');
        $items = [];

        foreach ($query->get() as $policy) {
            $items[] = $this->policies->row($policy, $types->get((int) $policy->leave_type_id));
        }

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', 'min:1'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['required', 'date_format:Y-m-d'],
            'applies_to' => ['sometimes', 'array'],
            'applies_to.scope' => ['sometimes', 'string', 'in:COMPANY'],
            'accrual' => ['sometimes', 'array'],
            'accrual.mode' => ['sometimes', 'string', 'in:ANNUAL_FIXED,MONTHLY_FIXED,NONE'],
            'accrual.minutes_per_year' => ['sometimes', 'integer', 'min:0'],
            'payroll_treatment' => ['sometimes', 'nullable', 'string', 'in:PAID,PARTIAL,UNPAID,UNRESOLVED'],
            'paid_ratio_scaled' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999'],
            // Legacy nested shape accepted only for compatibility.
            'accrual.payroll_treatment' => ['sometimes', 'string', 'in:PAID,PARTIAL,UNPAID,UNRESOLVED'],
            'accrual.paid_ratio_scaled' => ['sometimes', 'integer', 'min:1', 'max:999999'],
            'carryover' => ['sometimes', 'nullable', 'array'],
            'min_notice_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'max_consecutive_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:366'],
            'requires_attachment_over_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:366'],
            'allow_half_day' => ['sometimes', 'boolean'],
            'legal_basis' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // version is deliberately not accepted from the client.
        unset($data['version']);

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.leave.policy.append',
                capability: 'time.leave.policy.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.leave.policy.published',
            ),
            $data,
            function () use ($data, $actor): array {
                $row = $this->policies->append($data, $actor->userId);

                return [
                    'http_status' => 201,
                    'body' => ApiResponse::created($row)->getData(true),
                    'resource_type' => 'leave_policy_version',
                    'resource_id' => (string) $row['id'],
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    public function retire(Request $request, int $id): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.leave.policy.retire',
                capability: 'time.leave.policy.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.leave.policy.retired',
            ),
            ['policy_id' => $id, 'reason' => (string) $data['reason']],
            function () use ($id, $data, $actor): array {
                $row = $this->policies->retire($id, (string) $data['reason'], $actor->userId);

                return [
                    'http_status' => 200,
                    'body' => ApiResponse::ok($row)->getData(true),
                    'resource_type' => 'leave_policy_version',
                    'resource_id' => (string) $id,
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    private function respond(CommandResult $result): JsonResponse
    {
        $response = new JsonResponse($result->body, $result->status);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
