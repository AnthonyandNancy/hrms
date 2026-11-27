<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\LeaveRequestService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Explicit on-behalf leave command. The subject is required and is never
 * derived from the caller's own employment. Row scope is checked before the
 * service is called; an out-of-scope employment is a 404.
 */
final class LeaveOnBehalfController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly LeaveRequestService $requests,
        private readonly CommandRunner $commands,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'leaveTypeCode' => ['required', 'string', 'max:32'],
            'startDate' => ['required', 'date_format:Y-m-d'],
            'endDate' => ['required', 'date_format:Y-m-d'],
            'halfDay' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'attachment_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $employmentId = (int) $data['employment_id'];

        if ($actor->employmentId === $employmentId) {
            // On-behalf means a genuinely different subject. Self submit uses
            // the dedicated self command and capability.
            throw new NotFoundOrForbiddenException;
        }

        $employment = $this->scope->employment($actor, $employmentId, (string) $data['startDate']);

        $payload = [
            'employment_id' => $employment->employmentId,
            'leaveTypeCode' => (string) $data['leaveTypeCode'],
            'startDate' => (string) $data['startDate'],
            'endDate' => (string) $data['endDate'],
            'halfDay' => (bool) ($data['halfDay'] ?? false),
            'reason' => trim((string) $data['reason']),
            'attachment_reference' => isset($data['attachment_reference']) ? (string) $data['attachment_reference'] : null,
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.leave.request.on_behalf',
                capability: 'time.leave.request.on-behalf',
                anchor: AnchorType::MANAGED,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.leave.request.on_behalf_submitted',
            ),
            $payload,
            function () use ($actor, $payload, $employment): array {
                $body = $this->requests->requestOnBehalf(
                    employmentId: (int) $payload['employment_id'],
                    actorUserId: $actor->userId,
                    leaveTypeCode: (string) $payload['leaveTypeCode'],
                    fromDate: (string) $payload['startDate'],
                    toDate: (string) $payload['endDate'],
                    halfDay: (bool) $payload['halfDay'],
                    reason: (string) $payload['reason'],
                    orgUnitId: $employment->orgUnitId,
                    attachmentReference: $payload['attachment_reference'],
                );

                return [
                    'http_status' => 201,
                    'body' => ApiResponse::created($body)->getData(true),
                    'resource_type' => 'leave_request',
                    'resource_id' => (string) $body['case_id'],
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    /**
     * Preview an on-behalf request against the explicit subject's balance,
     * calendar and overlapping requests. Self preview never accepts a subject.
     */
    public function preview(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'leaveTypeCode' => ['required', 'string', 'max:32'],
            'startDate' => ['required', 'date_format:Y-m-d'],
            'endDate' => ['required', 'date_format:Y-m-d'],
            'halfDay' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'attachment_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $employmentId = (int) $data['employment_id'];

        if ($actor->employmentId === $employmentId) {
            throw new NotFoundOrForbiddenException;
        }

        $this->scope->employment($actor, $employmentId, (string) $data['startDate']);
        $preview = $this->requests->preview(
            employmentId: $employmentId,
            leaveTypeCode: (string) $data['leaveTypeCode'],
            fromDate: (string) $data['startDate'],
            toDate: (string) $data['endDate'],
            halfDay: (bool) ($data['halfDay'] ?? false),
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
        );

        return ApiResponse::ok([
            'workingDays' => (int) $preview['working_days'],
            'requestedDays' => $preview['requested_days'],
            'availableBefore' => (float) $preview['available_before_days'],
            'availableAfter' => (float) $preview['available_after_days'],
            'unit' => (string) $preview['unit'],
            'conflicts' => $preview['conflicts'],
            'note' => (string) $preview['note'],
            'leaveTypeCode' => (string) $preview['leave_type_code'],
            'requestedMinutes' => (int) $preview['requested_minutes'],
            'availableBeforeMinutes' => (int) $preview['available_before_minutes'],
            'availableAfterMinutes' => (int) $preview['available_after_minutes'],
            'standardDayMinutes' => (int) $preview['standard_day_minutes'],
            'policyVersion' => (int) $preview['policy_version'],
            'policyVersionId' => (int) $preview['policy_version_id'],
        ]);
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
