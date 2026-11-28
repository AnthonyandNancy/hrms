<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\PunchRecord;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PunchService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly BusinessCalendar $calendar,
        private readonly PayrollFreezeGuard $freezeGuard,
    ) {}

    public function record(
        int $employmentId,
        string $punchedAtUtc,
        string $source,
        string $rawPayloadHash,
        ?string $deviceId = null,
        ?string $sourceLocalOffset = null,
    ): PunchRecord {
        if (! in_array($source, ['DEVICE', 'MANUAL', 'IMPORT'], true)) {
            throw new ValidationException(['source' => ['Punch source must be DEVICE, MANUAL or IMPORT.']]);
        }

        if ($rawPayloadHash === '') {
            throw new ValidationException(['raw_payload_hash' => ['A payload hash is required.']]);
        }

        $punched = CarbonImmutable::parse($punchedAtUtc, 'UTC')->utc();

        if (! preg_match('/^[0-9a-f]{64}$/i', $rawPayloadHash)) {
            $rawPayloadHash = hash('sha256', $rawPayloadHash);
        }

        $existing = PunchRecord::query()
            ->where('employment_id', $employmentId)
            ->where('raw_payload_hash', $rawPayloadHash)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $timezone = $this->calendar->timezone()
            ?? (string) config('hrms.default_timezone', 'Asia/Shanghai');
        $period = $punched->setTimezone($timezone)->format('Y-m');

        return $this->freezeGuard->withOpenPeriods([$period], function () use (
            $employmentId,
            $deviceId,
            $punched,
            $sourceLocalOffset,
            $source,
            $rawPayloadHash,
        ): PunchRecord {
            try {
                return PunchRecord::query()->create([
                    'employment_id' => $employmentId,
                    'device_id' => $deviceId,
                    'punched_at_utc' => $punched,
                    'source_local_offset' => $sourceLocalOffset,
                    'source' => $source,
                    'raw_payload_hash' => $rawPayloadHash,
                    'created_at' => $this->clock->now(),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                return PunchRecord::query()
                    ->where('employment_id', $employmentId)
                    ->where('raw_payload_hash', $rawPayloadHash)
                    ->firstOrFail();
            }
        });
    }

    /**
     * Import a batch; repeated hashes are no-ops so device retries are safe.
     *
     * @param  list<array{punched_at_utc: string, source?: string, device_id?: string, source_local_offset?: string, raw_payload_hash?: string}>  $punches
     * @return list<PunchRecord>
     */
    public function import(int $employmentId, array $punches): array
    {
        if ($punches === []) {
            throw new ValidationException(['punches' => ['At least one punch is required.']]);
        }

        $validated = [];

        foreach ($punches as $index => $punch) {
            $source = (string) ($punch['source'] ?? 'IMPORT');

            if (! in_array($source, ['DEVICE', 'MANUAL', 'IMPORT'], true)) {
                throw new ValidationException(['punches.'.$index.'.source' => ['Each punch source must be DEVICE, MANUAL or IMPORT.']]);
            }

            $hash = isset($punch['raw_payload_hash']) && (string) $punch['raw_payload_hash'] !== ''
                ? (string) $punch['raw_payload_hash']
                : hash('sha256', $employmentId.'|'.$punch['punched_at_utc'].'|'.(string) ($punch['device_id'] ?? ''));

            $validated[] = [
                ...$punch,
                'source' => $source,
                'raw_payload_hash' => $hash,
            ];
        }

        return DB::transaction(function () use ($employmentId, $validated): array {
            $records = [];

            foreach ($validated as $punch) {
                $records[] = $this->record(
                    employmentId: $employmentId,
                    punchedAtUtc: (string) $punch['punched_at_utc'],
                    source: (string) $punch['source'],
                    rawPayloadHash: (string) $punch['raw_payload_hash'],
                    deviceId: isset($punch['device_id']) ? (string) $punch['device_id'] : null,
                    sourceLocalOffset: isset($punch['source_local_offset']) ? (string) $punch['source_local_offset'] : null,
                );
            }

            return $records;
        });
    }
}
