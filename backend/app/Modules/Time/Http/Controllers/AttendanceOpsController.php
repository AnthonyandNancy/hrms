<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\AttendanceOpsService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\TenantBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * K4 attendance operations surface: rule versions, per-day shift schedules,
 * the monthly closing list and the read-only device inventory.
 *
 * Every read goes through the projection envelope (ProjectionResponder) and
 * every write through CommandRunner, so idempotency and replay headers are
 * uniform with the rest of the kernel rather than per-controller.
 */
final class AttendanceOpsController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly TimeRequestScope $scope,
        private readonly TenantBusinessDate $businessDates,
        private readonly AttendanceOpsService $operations,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
    ) {}

    public function rules(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->resolve(
            $request->query('as_of') !== null ? (string) $request->query('as_of') : null,
            $principal->tenantId(),
        )->value();

        $this->assertRequestedLegalEntity($request, $principal, $asOf);

        $items = $this->operations->ruleVersions($principal->tenantId(), $asOf);

        return $this->projections->ok(new ProjectionResult(
            items: $items,
            page: 1,
            pageSize: max(1, count($items)),
            total: count($items),
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: '',
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'attendance_rule_versions'],
        ));
    }

    public function publishRule(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'version' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'effective_from' => ['required', new BusinessDateRule],
            'config' => ['required', 'array'],
        ]);

        $this->assertRequestedLegalEntity($request, $principal, (string) $data['effective_from']);

        $payload = [
            'version' => (string) $data['version'],
            'effective_from' => (string) $data['effective_from'],
            'config' => (array) $data['config'],
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.attendance.rule.publish',
                capability: 'time.attendance.rule.manage',
                anchor: AnchorType::LEGAL_ENTITY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.attendance.rule.published',
            ),
            $payload,
            function () use ($principal, $payload): array {
                $published = $this->operations->publishRuleVersion(
                    tenantId: $principal->tenantId(),
                    actorUserId: $principal->userId(),
                    version: (string) $payload['version'],
                    effectiveFrom: (string) $payload['effective_from'],
                    config: (array) $payload['config'],
                );

                return [
                    'http_status' => 201,
                    'body' => ApiResponse::created($this->ruleRow($published['row'], $published['replayed']))->getData(true),
                    'resource_type' => 'attendance_rule_version',
                    'resource_id' => (string) $published['row']->id,
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->commandResponse($result->status, $result->body, $result->replayed);
    }

    public function schedules(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $actor = $this->scope->actor($request);
        $asOf = $this->businessDates->resolve(
            $request->query('as_of') !== null ? (string) $request->query('as_of') : null,
            $principal->tenantId(),
        )->value();

        $data = $request->validate([
            'employment_id' => ['nullable', 'integer', 'min:1'],
            'legal_entity_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', new BusinessDateRule],
            'to' => ['nullable', new BusinessDateRule],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $from = isset($data['from']) ? (string) $data['from'] : null;
        $to = isset($data['to']) ? (string) $data['to'] : null;

        if ($from !== null && $to !== null && $to < $from) {
            throw new ValidationException(['to' => ['The end date must not precede the start date.']]);
        }

        $legalEntityId = isset($data['legal_entity_id']) ? (int) $data['legal_entity_id'] : null;

        if ($legalEntityId !== null) {
            $this->scope->assertLegalEntity($actor, $legalEntityId, $asOf);
        }

        $page = isset($data['page']) ? (int) $data['page'] : 1;
        $pageSize = isset($data['page_size']) ? (int) $data['page_size'] : 20;

        $result = $this->operations->schedules(
            actor: $actor,
            filters: [
                'employment_id' => isset($data['employment_id']) ? (int) $data['employment_id'] : null,
                'legal_entity_id' => $legalEntityId,
                'from' => $from,
                'to' => $to,
            ],
            page: $page,
            pageSize: $pageSize,
        );

        return $this->projections->ok(new ProjectionResult(
            items: $result['items'],
            page: $page,
            pageSize: $pageSize,
            total: $result['total'],
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: '',
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'shift_schedules'],
        ));
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'business_date' => ['required', new BusinessDateRule],
            'shift_template_id' => ['required', 'integer', 'min:1'],
        ]);

        $employment = $this->scope->employment(
            $actor,
            (int) $data['employment_id'],
            (string) $data['business_date'],
        );

        $payload = [
            'employment_id' => $employment->employmentId,
            'legal_entity_id' => $employment->legalEntityId,
            'business_date' => (string) $data['business_date'],
            'shift_template_id' => (int) $data['shift_template_id'],
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.shift.schedule.create',
                capability: 'time.shift.schedule.manage',
                anchor: AnchorType::LEGAL_ENTITY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.shift.schedule.created',
            ),
            $payload,
            function () use ($principal, $payload): array {
                $scheduled = $this->operations->scheduleShift(
                    tenantId: $principal->tenantId(),
                    legalEntityId: (int) $payload['legal_entity_id'],
                    employmentId: (int) $payload['employment_id'],
                    businessDate: (string) $payload['business_date'],
                    shiftTemplateId: (int) $payload['shift_template_id'],
                    actorUserId: $principal->userId(),
                );

                return [
                    'http_status' => 201,
                    'body' => ApiResponse::created([
                        'schedule_id' => (int) $scheduled['row']->id,
                        'legal_entity_id' => (int) $scheduled['row']->legal_entity_id,
                        'employment_id' => (int) $scheduled['row']->employment_id,
                        'business_date' => $scheduled['row']->business_date->format('Y-m-d'),
                        'shift_template_id' => (int) $scheduled['row']->shift_template_id,
                        'replayed' => $scheduled['replayed'],
                    ])->getData(true),
                    'resource_type' => 'shift_schedule',
                    'resource_id' => (string) $scheduled['row']->id,
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->commandResponse($result->status, $result->body, $result->replayed);
    }

    public function closings(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $actor = $this->scope->actor($request);
        $asOf = $this->businessDates->resolve(
            $request->query('as_of') !== null ? (string) $request->query('as_of') : null,
            $principal->tenantId(),
        )->value();

        $data = $request->validate([
            'legal_entity_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $legalEntityId = isset($data['legal_entity_id']) ? (int) $data['legal_entity_id'] : null;

        if ($legalEntityId !== null) {
            $this->scope->assertLegalEntity($actor, $legalEntityId, $asOf);
        }

        $page = isset($data['page']) ? (int) $data['page'] : 1;
        $pageSize = isset($data['page_size']) ? (int) $data['page_size'] : 20;

        $result = $this->operations->closures(
            tenantId: $principal->tenantId(),
            legalEntityIds: $this->scope->legalEntityIds($actor, $asOf),
            legalEntityId: $legalEntityId,
            page: $page,
            pageSize: $pageSize,
        );

        return $this->projections->ok(new ProjectionResult(
            items: $result['items'],
            page: $page,
            pageSize: $pageSize,
            total: $result['total'],
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: '',
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'attendance_period_closures'],
        ));
    }

    public function devices(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $actor = $this->scope->actor($request);
        $asOf = $this->businessDates->resolve(
            $request->query('as_of') !== null ? (string) $request->query('as_of') : null,
            $principal->tenantId(),
        )->value();

        $data = $request->validate([
            'legal_entity_id' => ['nullable', 'integer', 'min:1'],
            'active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $legalEntityId = isset($data['legal_entity_id']) ? (int) $data['legal_entity_id'] : null;

        if ($legalEntityId !== null) {
            $this->scope->assertLegalEntity($actor, $legalEntityId, $asOf);
        }

        $page = isset($data['page']) ? (int) $data['page'] : 1;
        $pageSize = isset($data['page_size']) ? (int) $data['page_size'] : 20;

        $result = $this->operations->devices(
            tenantId: $principal->tenantId(),
            legalEntityIds: $this->scope->legalEntityIds($actor, $asOf),
            legalEntityId: $legalEntityId,
            active: array_key_exists('active', $data) ? (bool) $data['active'] : null,
            page: $page,
            pageSize: $pageSize,
        );

        return $this->projections->ok(new ProjectionResult(
            items: $result['items'],
            page: $page,
            pageSize: $pageSize,
            total: $result['total'],
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: '',
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'attendance_devices'],
        ));
    }

    /**
     * Rules are tenant-wide rows, so the caller must still prove they can read
     * the legal entity they are asking about. Without an explicit
     * legal_entity_id the actor's own scope is used.
     */
    private function assertRequestedLegalEntity(Request $request, Principal $principal, string $businessDate): void
    {
        $requested = $request->query('legal_entity_id');

        if ($requested === null || $requested === '') {
            return;
        }

        if (! is_numeric($requested) || (int) $requested < 1) {
            throw new ValidationException(['legal_entity_id' => ['The legal_entity_id must be a positive integer.']]);
        }

        $actor = $this->scope->actor($request);
        $this->scope->assertLegalEntity($actor, (int) $requested, $businessDate);
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleRow(\App\Modules\Time\Models\AttendanceRuleVersion $row, bool $replayed): array
    {
        return [
            'id' => (int) $row->id,
            'version' => (string) $row->version,
            'effective_from' => $row->effective_from->format('Y-m-d'),
            'checksum' => (string) $row->checksum,
            'config' => (array) $row->config_json,
            'replayed' => $replayed,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function commandResponse(int $status, array $body, bool $replayed): JsonResponse
    {
        $response = new JsonResponse($body, $status);

        if ($replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
