<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\ShiftAssignment;
use App\Modules\Time\Models\ShiftTemplate;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;

final class ShiftService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly BusinessCalendar $calendar,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTemplate(int $tenantId, array $data): ShiftTemplate
    {
        $legalEntityId = (int) ($data['legal_entity_id'] ?? 0);
        $timezone = $this->calendar->timezone($tenantId, $legalEntityId);

        if ($timezone === null) {
            throw new ValidationException([
                'legal_entity_id' => ['Configure the legal entity calendar before creating shifts.'],
            ]);
        }

        $start = (string) ($data['start_local_time'] ?? '');
        $end = (string) ($data['end_local_time'] ?? '');
        $crossesMidnight = (bool) ($data['crosses_midnight'] ?? (strcmp($end, $start) <= 0));

        (new ShiftWindow)->boundaries(
            $timezone,
            '2026-01-05',
            $start,
            $end,
            $crossesMidnight,
        );

        $validFrom = (string) ($data['valid_from'] ?? $this->clock->today($timezone)->format('Y-m-d'));
        $validTo = (string) ($data['valid_to'] ?? '9999-12-31');
        $this->assertValidity($validFrom, $validTo);

        return ShiftTemplate::query()->create([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
            'code' => (string) $data['code'],
            'name' => (string) ($data['name'] ?? $data['code']),
            'start_local_time' => $start,
            'end_local_time' => $end,
            'crosses_midnight' => $crossesMidnight,
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'late_grace_minutes' => (int) ($data['late_grace_minutes'] ?? 0),
            'early_grace_minutes' => (int) ($data['early_grace_minutes'] ?? 0),
            'standard_hours_decimal' => (string) ($data['standard_hours_decimal'] ?? '8.000000'),
            'version' => (int) ($data['version'] ?? 1),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'source_type' => (string) ($data['source_type'] ?? 'MANUAL'),
            'source_id' => isset($data['source_id']) ? (string) $data['source_id'] : null,
            'recorded_at' => $this->clock->now(),
        ]);
    }

    public function assign(
        int $tenantId,
        int $employmentId,
        int $shiftTemplateId,
        string $validFrom,
        string $validTo = '9999-12-31',
        string $source = 'MANUAL',
    ): ShiftAssignment {
        $this->assertValidity($validFrom, $validTo);

        $template = ShiftTemplate::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($shiftTemplateId)
            ->firstOrFail();

        if ($template->valid_from->format('Y-m-d') > $validFrom || $template->valid_to->format('Y-m-d') <= $validFrom) {
            throw new ValidationException([
                'shift_template_id' => ['The shift template is not valid on the assignment start date.'],
            ]);
        }

        return ShiftAssignment::query()->create([
            'tenant_id' => $tenantId,
            'employment_id' => $employmentId,
            'shift_template_id' => $shiftTemplateId,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'source' => $source,
            'created_at' => $this->clock->now(),
        ]);
    }

    private function assertValidity(string $validFrom, string $validTo): void
    {
        BusinessDate::parse($validFrom);
        BusinessDate::parse($validTo);

        if ($validFrom >= $validTo) {
            throw new ValidationException(['valid_to' => ['valid_to must be after valid_from (half-open interval).']]);
        }
    }
}
