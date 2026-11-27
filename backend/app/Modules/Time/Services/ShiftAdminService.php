<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\ShiftTemplate;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Capability-guarded shift administration.
 *
 * Shift history is append-only in effect: an edit inserts a new version row
 * and supersedes the current row instead of rewriting its business fields.
 * Disable also supersedes the current row but keeps it queryable. A code may
 * have multiple historical versions; the "current" row is the unsuperseded
 * one.
 */
final class ShiftAdminService
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly BusinessCalendar $calendar,
        private readonly AuditRecorder $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $actorUserId): ShiftTemplate
    {
        $this->assertCodeAndWindow($data);
        $timezone = $this->calendar->timezone();

        if ($timezone === null) {
            throw new ValidationException(['calendar' => ['Configure the company calendar before creating shifts.']]);
        }

        return DB::transaction(function () use ($data, $timezone, $actorUserId): ShiftTemplate {
            $code = strtoupper(trim((string) $data['code']));
            $validFrom = (string) ($data['valid_from'] ?? $this->clock->today($timezone)->format('Y-m-d'));

            $current = ShiftTemplate::query()
                ->where('code', $code)
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->first();

            if ($current !== null) {
                if ((string) $current->start_local_time === $this->normaliseClock((string) $data['start_local_time'])
                    && (string) $current->end_local_time === $this->normaliseClock((string) $data['end_local_time'])
                    && $current->valid_from->format('Y-m-d') === $validFrom) {
                    return $current;
                }

                throw new ConflictException(
                    'An active shift version already uses this code; edit it to create a new version.',
                    ['reason' => 'SHIFT_CODE_ACTIVE'],
                );
            }

            $row = $this->shifts->createTemplate([
                ...$data,
                'code' => $code,
                'valid_from' => $validFrom,
                'source_type' => (string) ($data['source_type'] ?? 'MANUAL'),
            ]);

            $this->audit->record(
                $actorUserId,
                'time.shift.created',
                'shift_template',
                (int) $row->id,
                after: $this->auditPayload($row),
            );

            return $row;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(int $id, array $changes, int $actorUserId): ShiftTemplate
    {
        return DB::transaction(function () use ($id, $changes, $actorUserId): ShiftTemplate {
            $current = $this->currentOrFail($id);
            $timezone = $this->calendar->timezone();

            if ($timezone === null) {
                throw new ValidationException(['calendar' => ['Configure the company calendar before editing shifts.']]);
            }

            $merged = [
                'code' => (string) ($changes['code'] ?? $current->code),
                'name' => (string) ($changes['name'] ?? $current->name),
                'start_local_time' => (string) ($changes['start_local_time'] ?? $current->start_local_time),
                'end_local_time' => (string) ($changes['end_local_time'] ?? $current->end_local_time),
                'crosses_midnight' => array_key_exists('crosses_midnight', $changes)
                    ? (bool) $changes['crosses_midnight']
                    : (bool) $current->crosses_midnight,
                'break_minutes' => (int) ($changes['break_minutes'] ?? $current->break_minutes),
                'late_grace_minutes' => (int) ($changes['late_grace_minutes'] ?? $current->late_grace_minutes),
                'early_grace_minutes' => (int) ($changes['early_grace_minutes'] ?? $current->early_grace_minutes),
                'standard_hours_decimal' => (string) ($changes['standard_hours_decimal'] ?? $current->standard_hours_decimal),
                'valid_from' => (string) ($changes['valid_from'] ?? $current->valid_from->format('Y-m-d')),
                'valid_to' => (string) ($changes['valid_to'] ?? $current->valid_to->format('Y-m-d')),
            ];

            $this->assertCodeAndWindow($merged);
            $this->assertNoSameWindowVersion($current, $merged);

            $current->forceFill([
                'superseded_at' => $this->clock->now(),
                'valid_to' => (string) $merged['valid_from'],
            ])->save();

            $row = $this->shifts->createTemplate([
                ...$merged,
                'code' => strtoupper(trim($merged['code'])),
                'version' => (int) $current->version + 1,
                'source_type' => 'MANUAL',
                'source_id' => (string) $current->id,
                'valid_from' => $merged['valid_from'],
                'valid_to' => $merged['valid_to'],
            ]);

            $this->audit->record(
                $actorUserId,
                'time.shift.versioned',
                'shift_template',
                (int) $row->id,
                before: $this->auditPayload($current),
                after: $this->auditPayload($row),
            );

            return $row;
        });
    }

    public function disable(int $id, string $reason, int $actorUserId): ShiftTemplate
    {
        return DB::transaction(function () use ($id, $reason, $actorUserId): ShiftTemplate {
            if (trim($reason) === '') {
                throw new ValidationException(['reason' => ['A disable reason is required.']]);
            }

            $current = $this->currentOrFail($id);
            $today = $this->clock->today(
                $this->calendar->timezone() ?? (string) config('hrms.default_timezone', 'Asia/Shanghai'),
            )->format('Y-m-d');

            if ($today < $current->valid_from->format('Y-m-d')) {
                $validFrom = $current->valid_from->format('Y-m-d');
            } else {
                $validFrom = $today;
            }

            if ($validFrom >= $current->valid_to->format('Y-m-d')) {
                throw new ConflictException(
                    'The shift is already outside its validity window.',
                    ['reason' => 'SHIFT_ALREADY_DISABLED'],
                );
            }

            if ($validFrom < $current->valid_from->format('Y-m-d')) {
                throw new ConflictException(
                    'The shift has not started; edit or remove its validity instead of disabling before its start.',
                    ['reason' => 'SHIFT_NOT_STARTED'],
                );
            }

            // Disable is effective-dated, not destructive: close the current
            // validity window at the disable date. The row id and all schedule
            // references stay intact, and `superseded_at` remains null so the
            // historical current row can still be inspected.
            $current->forceFill([
                'valid_to' => $validFrom,
            ])->save();

            $this->audit->record(
                $actorUserId,
                'time.shift.disabled',
                'shift_template',
                (int) $current->id,
                after: [
                    ...$this->auditPayload($current),
                    'reason' => $reason,
                ],
            );

            return $current->refresh();
        });
    }

    private function currentOrFail(int $id): ShiftTemplate
    {
        $row = ShiftTemplate::query()
            ->whereKey($id)
            ->whereNull('superseded_at')
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(ShiftTemplate $row): array
    {
        return [
            'code' => (string) $row->code,
            'version' => (int) $row->version,
            'valid_from' => $row->valid_from->format('Y-m-d'),
            'valid_to' => $row->valid_to->format('Y-m-d'),
            'superseded_at' => $row->superseded_at !== null ? \Carbon\CarbonImmutable::parse((string) $row->superseded_at)->toIso8601ZuluString() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertCodeAndWindow(array $data): void
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/', $code) !== 1) {
            throw new ValidationException([
                'code' => ['Shift code must be 1-32 uppercase letters, digits, dot, underscore or hyphen.'],
            ]);
        }

        $start = $this->normaliseClock((string) ($data['start_local_time'] ?? ''));
        $end = $this->normaliseClock((string) ($data['end_local_time'] ?? ''));

        if ($start === '' || $end === '') {
            throw new ValidationException(['start_local_time' => ['Shift start and end local times are required.']]);
        }

        $timezone = $this->calendar->timezone();

        if ($timezone !== null) {
            // Reuse the single shift-window implementation for overnight and
            // non-overnight validation instead of duplicating the rules.
            (new ShiftWindow)->boundaries(
                $timezone,
                '2026-01-05',
                $start,
                $end,
                (bool) ($data['crosses_midnight'] ?? (strcmp($end, $start) <= 0)),
            );
        }

        $validFrom = (string) ($data['valid_from'] ?? '');
        $validTo = (string) ($data['valid_to'] ?? '9999-12-31');

        if ($validFrom !== '') {
            BusinessDate::parse($validFrom);
            BusinessDate::parse($validTo);
        }

        foreach (['break_minutes', 'late_grace_minutes', 'early_grace_minutes'] as $field) {
            $value = (int) ($data[$field] ?? 0);

            if ($value < 0 || $value > 1440) {
                throw new ValidationException([$field => ['Grace and break minutes must be between 0 and 1440.']]);
            }
        }

        $hours = (string) ($data['standard_hours_decimal'] ?? '8.000000');

        if (preg_match('/^\d{1,2}(?:\.\d{1,6})?$/', $hours) !== 1) {
            throw new ValidationException([
                'standard_hours_decimal' => ['Standard hours must be a decimal string with at most six fractional digits.'],
            ]);
        }

        if ((float) $hours <= 0 || (float) $hours > 24) {
            throw new ValidationException(['standard_hours_decimal' => ['Standard hours must be greater than 0 and at most 24.']]);
        }

        $this->validateRange($validFrom, $validTo);
    }

    /**
     * @param  array<string, mixed>  $merged
     */
    private function assertNoSameWindowVersion(ShiftTemplate $current, array $merged): void
    {
        $duplicate = ShiftTemplate::query()
            ->where('code', strtoupper(trim((string) $merged['code'])))
            ->where('version', (int) $current->version + 1)
            ->where('valid_from', (string) $merged['valid_from'])
            ->exists();

        if ($duplicate) {
            throw new ConflictException(
                'The requested shift version already exists.',
                ['reason' => 'SHIFT_VERSION_CONFLICT'],
            );
        }
    }

    private function validateRange(string $validFrom, string $validTo): void
    {
        if ($validFrom === '') {
            return;
        }

        if ($validFrom >= $validTo) {
            throw new ValidationException(['valid_to' => ['valid_to must be after valid_from (half-open interval).']]);
        }
    }

    private function normaliseClock(string $value): string
    {
        $trimmed = trim($value);

        if (preg_match('/^\d{2}:\d{2}$/', $trimmed) === 1) {
            return $trimmed.':00';
        }

        return $trimmed;
    }
}
