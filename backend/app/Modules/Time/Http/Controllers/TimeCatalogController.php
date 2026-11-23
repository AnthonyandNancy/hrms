<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Models\Holiday;
use App\Modules\Time\Models\ShiftTemplate;
use App\Modules\Time\Services\BusinessCalendar;
use App\Shared\ApiResponse;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TimeCatalogController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly BusinessCalendar $calendar,
    ) {}

    public function calendar(Request $request, int $legalEntityId): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $year = (int) $request->query('year', 0);

        if ($year < 1970 || $year > 9999) {
            throw new ValidationException(['year' => ['A four-digit calendar year is required.']]);
        }

        $asOf = $year.'-01-01';
        $this->scope->assertLegalEntity($actor, $legalEntityId, $asOf);

        $calendar = $this->calendar->calendar($actor->tenantId, $legalEntityId);

        if ($calendar === null) {
            throw new NotFoundOrForbiddenException;
        }

        $holidays = Holiday::query()
            ->where('tenant_id', $actor->tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('date', '>=', $year.'-01-01')
            ->where('date', '<', ($year + 1).'-01-01')
            ->orderBy('date')
            ->get()
            ->map(static fn (Holiday $holiday): array => [
                'date' => \Carbon\CarbonImmutable::parse((string) $holiday->date)->format('Y-m-d'),
                'name' => (string) $holiday->name,
                'type' => (string) $holiday->type,
                'source' => $holiday->source,
            ])
            ->all();

        return ApiResponse::ok([
            'legal_entity_id' => $legalEntityId,
            'year' => $year,
            'timezone' => (string) $calendar->timezone,
            'workweek' => array_values((array) $calendar->workweek_json),
            'standard_day_minutes' => (int) $calendar->standard_day_minutes,
            'version' => (int) $calendar->version,
            'holidays' => $holidays,
        ]);
    }

    public function shifts(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $ids = $this->scope->legalEntityIds($actor, BusinessDate::today((string) config('hrms.default_timezone', 'Asia/Shanghai'), app(\App\Shared\Time\Clock::class))->value());

        $items = ShiftTemplate::query()
            ->where('tenant_id', $actor->tenantId)
            ->where(function ($query) use ($ids): void {
                if ($ids === []) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->whereIn('legal_entity_id', $ids);
            })
            ->orderBy('legal_entity_id')
            ->orderBy('code')
            ->orderByDesc('version')
            ->get()
            ->map(static fn (ShiftTemplate $shift): array => [
                'id' => (int) $shift->id,
                'legal_entity_id' => (int) $shift->legal_entity_id,
                'code' => (string) $shift->code,
                'name' => (string) $shift->name,
                'start_local_time' => (string) $shift->start_local_time,
                'end_local_time' => (string) $shift->end_local_time,
                'crosses_midnight' => (bool) $shift->crosses_midnight,
                'break_minutes' => (int) $shift->break_minutes,
                'late_grace_minutes' => (int) $shift->late_grace_minutes,
                'early_grace_minutes' => (int) $shift->early_grace_minutes,
                'standard_hours_decimal' => (string) $shift->standard_hours_decimal,
                'version' => (int) $shift->version,
                'valid_from' => $shift->valid_from->format('Y-m-d'),
                'valid_to' => $shift->valid_to->format('Y-m-d'),
            ])
            ->all();

        return ApiResponse::ok([
            'items' => $items,
            'total' => count($items),
        ]);
    }
}
