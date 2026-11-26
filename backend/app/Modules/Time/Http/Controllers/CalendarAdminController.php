<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\Time\Models\Holiday;
use App\Modules\Time\Models\LegalEntityCalendar;
use App\Modules\Time\Services\BusinessCalendar;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Publishes one full legal-entity calendar version. The version is the
 * optimistic concurrency token; holidays are replaced by the published
 * snapshot in the same transaction and the change is audited.
 */
final class CalendarAdminController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly OrganizationFacts $organization,
        private readonly BusinessCalendar $calendar,
        private readonly CommandRunner $commands,
        private readonly AuditRecorder $audit,
        private readonly Clock $clock,
    ) {}

    public function publish(Request $request, int $legalEntityId): JsonResponse
    {
        $principal = $this->principals->resolve();

        if ($this->organization->legalEntity($principal->tenantId(), $legalEntityId) === null) {
            throw new NotFoundOrForbiddenException;
        }

        $data = $request->validate([
            'timezone' => ['required', 'string', 'max:64'],
            'workweek' => ['required', 'array', 'min:1', 'max:7'],
            'workweek.*' => ['integer', 'min:1', 'max:7', 'distinct'],
            'standard_day_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'holidays' => ['sometimes', 'array', 'max:500'],
            'holidays.*.date' => ['required', 'date_format:Y-m-d'],
            'holidays.*.name' => ['required', 'string', 'max:160'],
            'holidays.*.type' => ['required', 'string', 'in:PUBLIC,COMPANY,MAKEUP'],
        ]);

        $payload = [
            'legal_entity_id' => $legalEntityId,
            'timezone' => (string) $data['timezone'],
            'workweek' => array_values(array_map('intval', (array) $data['workweek'])),
            'standard_day_minutes' => (int) ($data['standard_day_minutes'] ?? 480),
            'holidays' => array_values((array) ($data['holidays'] ?? [])),
        ];

        $result = $this->commands->run(
            definition: new CommandDefinition(
                name: 'time.calendar.publish',
                capability: 'time.calendar.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.calendar.published',
            ),
            payload: $payload,
            operation: fn (): array => $this->publishSnapshot($principal->tenantId(), $payload),
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        $response = ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * @param  array{legal_entity_id: int, timezone: string, workweek: list<int>, standard_day_minutes: int, holidays: list<array{date: string, name: string, type: string}>}  $payload
     */
    private function assertCalendarConfiguration(array $payload): void
    {
        if (! in_array($payload['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            throw new ValidationException(
                ['timezone' => ['The timezone must be a valid IANA timezone identifier.']],
                'The given data was invalid.',
                ['reason' => 'INVALID_TIMEZONE'],
            );
        }
    }

    /**
     * @param  array{legal_entity_id: int, timezone: string, workweek: list<int>, standard_day_minutes: int, holidays: list<array{date: string, name: string, type: string}>}  $payload
     * @return array{http_status: int, body: array<string, mixed>, resource_type: string, resource_id: string}
     */
    private function publishSnapshot(int $tenantId, array $payload): array
    {
        $legalEntityId = $payload['legal_entity_id'];

        return DB::transaction(function () use ($tenantId, $legalEntityId, $payload): array {
            // Validate the configuration before the first row exists so an
            // invalid publish does not leave a half-created calendar.
            $this->assertCalendarConfiguration($payload);

            /** @var LegalEntityCalendar|null $existing */
            $existing = LegalEntityCalendar::query()
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $legalEntityId)
                ->lockForUpdate()
                ->first();

            $before = $existing === null ? [] : [
                'version' => (int) $existing->version,
                'timezone' => (string) $existing->timezone,
                'workweek' => array_values((array) $existing->workweek_json),
            ];

            $this->calendar->configure(
                $tenantId,
                $legalEntityId,
                $payload['timezone'],
                $payload['workweek'],
                $payload['standard_day_minutes'],
            );

            /** @var LegalEntityCalendar $calendar */
            $calendar = LegalEntityCalendar::query()
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $legalEntityId)
                ->lockForUpdate()
                ->firstOrFail();

            $calendar->forceFill([
                'timezone' => $payload['timezone'],
                'workweek_json' => $payload['workweek'],
                'standard_day_minutes' => $payload['standard_day_minutes'],
                'version' => $existing === null ? 1 : (int) $calendar->version + 1,
                'updated_at' => $this->clock->now(),
            ])->save();

            Holiday::query()
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $legalEntityId)
                ->delete();

            foreach ($payload['holidays'] as $holiday) {
                $this->calendar->addHoliday(
                    $tenantId,
                    $legalEntityId,
                    (string) $holiday['date'],
                    (string) $holiday['name'],
                    (string) $holiday['type'],
                    'ADMIN_PUBLISH',
                );
            }

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: \App\Shared\RequestContext::userId(),
                action: 'time.calendar.published',
                aggregateType: 'legal_entity_calendar',
                aggregateId: (string) $legalEntityId,
                before: $before,
                after: [
                    'version' => (int) $calendar->version,
                    'timezone' => (string) $calendar->timezone,
                    'workweek' => array_values((array) $calendar->workweek_json),
                ],
                metadata: ['request_id' => \App\Shared\RequestContext::requestId()],
            );

            $holidays = Holiday::query()
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $legalEntityId)
                ->orderBy('date')
                ->get()
                ->map(static fn (Holiday $row): array => [
                    'date' => $row->date instanceof \Carbon\CarbonInterface ? $row->date->format('Y-m-d') : (string) $row->date,
                    'name' => (string) $row->name,
                    'type' => (string) $row->type,
                ])->all();

            return [
                'http_status' => 200,
                'body' => [
                    'legal_entity_id' => $legalEntityId,
                    'version' => (int) $calendar->version,
                    'timezone' => (string) $calendar->timezone,
                    'workweek' => array_values((array) $calendar->workweek_json),
                    'standard_day_minutes' => (int) $calendar->standard_day_minutes,
                    'holidays' => $holidays,
                ],
                'resource_type' => 'legal_entity_calendar',
                'resource_id' => (string) $legalEntityId,
            ];
        });
    }
}
