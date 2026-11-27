<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Modules\Time\Services\LeaveBalanceService;
use App\Modules\Time\Services\LeaveRequestService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HR/attendance administrator leave operations: scoped ledger reads, balance
 * drill-down and approved-leave revocation. These routes use time.leave.admin
 * and never trust a client-supplied capability flag.
 */
final class LeaveAdminController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveRequestService $requests,
        private readonly CommandRunner $commands,
    ) {}

    public function balances(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $asOf = $this->scope->resolveDate(isset($data['as_of']) ? (string) $data['as_of'] : null);
        $employmentId = isset($data['employment_id']) ? (int) $data['employment_id']
            : $actor->employmentId;

        if ($employmentId === null || $employmentId <= 0) {
            throw new NotFoundOrForbiddenException;
        }

        $this->scope->employment($actor, $employmentId, $asOf);

        return ApiResponse::ok(array_map(
            fn (array $balance): array => [
                'leaveTypeId' => (int) $balance['leave_type_id'],
                'leaveTypeCode' => (string) $balance['leave_type_code'],
                'leaveTypeName' => (string) $balance['leave_type_name'],
                'unit' => (string) $balance['unit'],
                'granted' => (int) $balance['granted'],
                'used' => abs((int) $balance['consumed']),
                'pending' => abs((int) $balance['reserved']),
                'available' => (int) $balance['available'],
                'asOf' => (string) $balance['as_of'],
                'standardDayMinutes' => (int) $balance['standard_day_minutes'],
                'grantedMinutes' => abs((int) $balance['granted']),
                'consumedMinutes' => abs((int) $balance['consumed']),
                'reservedMinutes' => abs((int) $balance['reserved']),
                'availableMinutes' => (int) $balance['available'],
                'balanceMinutes' => (int) $balance['balance_minutes'],
                'balanceDays' => $balance['balance_days'],
            ],
            $this->balances->outstanding($employmentId, $asOf),
        ));
    }

    public function revoke(Request $request, int $caseId): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $projection = LeaveRequestProjection::query()->where('case_id', $caseId)->first();

        if ($projection === null) {
            throw new ConflictException('The leave request was not found.', ['reason' => 'LEAVE_REQUEST_NOT_FOUND']);
        }

        if ($actor->scope === 'SELF') {
            // Admin revoke is a management command and never widens a self-only
            // actor just because a capability flag is present.
            throw new NotFoundOrForbiddenException;
        }

        $this->scope->employment($actor, (int) $projection->employment_id, $projection->from_date->format('Y-m-d'));

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.leave.request.admin_revoke',
                capability: 'time.leave.admin',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.leave.request.revoked',
            ),
            ['case_id' => $caseId, 'reason' => (string) $data['reason']],
            function () use ($caseId, $data, $actor): array {
                $body = $this->requests->cancel(
                    caseId: $caseId,
                    actorUserId: $actor->userId,
                    reason: (string) $data['reason'],
                    revoke: true,
                    managerOverride: true,
                );

                return [
                    'http_status' => 200,
                    'body' => ApiResponse::ok([
                        'caseId' => (int) $body['case_id'],
                        'state' => (string) $body['state'],
                        'releasedMinutes' => (int) $body['released_minutes'],
                        'reversedMinutes' => (int) $body['reversed_minutes'],
                        'replayed' => (bool) $body['replayed'],
                    ])->getData(true),
                    'resource_type' => 'leave_request',
                    'resource_id' => (string) $caseId,
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        $response = new JsonResponse($result->body, $result->status);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
