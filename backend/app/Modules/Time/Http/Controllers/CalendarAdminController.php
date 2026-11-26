<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Models\Holiday;
use App\Modules\Time\Models\LegalEntityCalendar;
use App\Modules\Time\Services\BusinessCalendar;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Publishes the single company calendar version. The version is the optimistic
 * concurrency token; holidays are replaced by the published snapshot in the
 * same transaction and the change is audited.
 */
final class CalendarAdminController
{
    /** Platform audit evidence keeps its compatibility scope column. */
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly BusinessCalendar $calendar,
        private readonly CommandRunner $commands,
        private readonly AuditRecorder $audit,
        private readonly Clock $clock,
    ) {}

    public function publish(Request $request): JsonResponse
    {
        $this->principals->resolve();

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
            operation: fn (): array => $this->publishSnapshot($payload),
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        $response = ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * @param  array{timezone: string, workweek: list<int>, standard_day_minutes: int, holidays: list<array{date: string, name: string, type: string}>}  $payload
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
     * @param  array{timezone: string, workweek: list<int>, standard_day_minutes: int, holidays: list<array{date: string, name: string, type: string}>}  $payload
     * @return array{http_status: int, body: array<string, mixed>, resource_type: string, resource_id: string}
     */
    private function publishSnapshot(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            // Validate the configuration before the first row exists so an
            // invalid publish does not leave a half-created calendar.
            $this->assertCalendarConfiguration($payload);

            /** @var LegalEntityCalendar|null $existing */
            $existing = LegalEntityCalendar::query()
                ->lockForUpdate()
                ->first();

            $before = $existing === null ? [] : [
                'version' => (int) $existing->version,
                'timezone' => (string) $existing->timezone,
                'workweek' => array_values((array) $existing->workweek_json),
            ];

            $this->calendar->configure(
                $payload['timezone'],
                $payload['workweek'],
                $payload['standard_day_minutes'],
            );

            /** @var LegalEntityCalendar $calendar */
            $calendar = LegalEntityCalendar::query()
                ->lockForUpdate()
                ->firstOrFail();

            $calendar->forceFill([
                'timezone' => $payload['timezone'],
                'workweek_json' => $payload['workweek'],
                'standard_day_minutes' => $payload['standard_day_minutes'],
                'version' => $existing === null ? 1 : (int) $calendar->version + 1,
                'updated_at' => $this->clock->now(),
            ])->save();

            Holiday::query()->delete();

            foreach ($payload['holidays'] as $holiday) {
                $this->calendar->addHoliday(
                    (string) $holiday['date'],
                    (string) $holiday['name'],
                    (string) $holiday['type'],
                    'ADMIN_PUBLISH',
                );
            }

            $this->audit->record(
                \App\Shared\RequestContext::userId(),
                'time.calendar.published',
                'company_calendar',
                (string) $calendar->id,
                before: $before,
                after: [
                    'version' => (int) $calendar->version,
                    'timezone' => (string) $calendar->timezone,
                    'workweek' => array_values((array) $calendar->workweek_json),
                ],
                metadata: ['request_id' => \App\Shared\RequestContext::requestId()],
            );

            $holidays = Holiday::query()
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
                    'version' => (int) $calendar->version,
                    'timezone' => (string) $calendar->timezone,
                    'workweek' => array_values((array) $calendar->workweek_json),
                    'standard_day_minutes' => (int) $calendar->standard_day_minutes,
                    'holidays' => $holidays,
                ],
                'resource_type' => 'company_calendar',
                'resource_id' => (string) $calendar->id,
            ];
        });
    }
}
