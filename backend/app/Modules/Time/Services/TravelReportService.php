<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\TravelReport;
use App\Modules\Time\Models\BusinessTripRecord;
use App\Modules\Time\Models\TravelExpense;
use Carbon\CarbonImmutable;

/**
 * Scoped travel statistics read model.
 *
 * Aggregation is performed over Time-owned rows only. Department membership is
 * resolved through the narrow EmploymentDirectory port; no People or
 * Organization table is read here, and no employee-level expense amount is
 * returned beyond the caller's already-authorized employment scope.
 */
final class TravelReportService
{
    public function __construct(private readonly EmploymentDirectory $employments) {}

    /**
     * @param  list<int>|null  $employmentScope  null means the whole company
     * @param  array{from: string, to: string, employment_id?: int|null, department_id?: int|null}  $filters
     */
    public function summary(?array $employmentScope, array $filters, string $asOf): TravelReport
    {
        $from = (string) $filters['from'];
        $to = (string) $filters['to'];
        $query = BusinessTripRecord::query()
            ->where('from_date', '>=', $from)
            ->where('from_date', '<=', $to);

        if ($employmentScope === []) {
            $query->whereRaw('1 = 0');
        } elseif ($employmentScope !== null) {
            $query->whereIn('employment_id', $employmentScope);
        }

        if (($filters['employment_id'] ?? null) !== null) {
            $query->where('employment_id', (int) $filters['employment_id']);
        }

        $trips = $query->orderBy('from_date')->orderBy('id')->get();
        $tripIds = $trips->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $expensesByTravel = [];

        if ($tripIds !== []) {
            TravelExpense::query()
                ->whereIn('travel_id', $tripIds)
                ->select(['travel_id', 'amount_cents'])
                ->get()
                ->each(function (TravelExpense $expense) use (&$expensesByTravel): void {
                    $travelId = (int) $expense->travel_id;
                    $expensesByTravel[$travelId] = ($expensesByTravel[$travelId] ?? 0) + (int) $expense->amount_cents;
                });
        }

        $rows = [];
        $requestedDepartmentId = ($filters['department_id'] ?? null) !== null
            ? (int) $filters['department_id']
            : null;

        foreach ($trips as $trip) {
            $fromDate = $trip->from_date->format('Y-m-d');
            $month = substr($fromDate, 0, 7);
            $departmentId = $requestedDepartmentId;
            $snapshot = $this->employments->snapshot(
                (int) $trip->employment_id,
                $trip->from_date->format('Y-m-d'),
            );
            $departmentId ??= $snapshot?->orgUnitId;
            $key = $month.'|'.($departmentId ?? 0);

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'month' => $month,
                    'department_id' => $departmentId,
                    'employment_count' => 0,
                    'approved_trips' => 0,
                    'pending_trips' => 0,
                    'rejected_trips' => 0,
                    'cancelled_trips' => 0,
                    'completed_trips' => 0,
                    'approved_days' => 0,
                    'pending_days' => 0,
                    'expense_cents' => 0,
                    'travel_ids' => [],
                    '_employments' => [],
                ];
            }

            $row = &$rows[$key];
            $days = $this->inclusiveDays($trip);

            match ((string) $trip->status) {
                'APPROVED' => (function () use (&$row, $days): void {
                    $row['approved_trips']++;
                    $row['approved_days'] += $days;
                })(),
                'PENDING_APPROVAL' => (function () use (&$row, $days): void {
                    $row['pending_trips']++;
                    $row['pending_days'] += $days;
                })(),
                'REJECTED' => $row['rejected_trips']++,
                'CANCELLED' => $row['cancelled_trips']++,
                'COMPLETED' => $row['completed_trips']++,
                default => null,
            };

            $row['expense_cents'] += $expensesByTravel[(int) $trip->id] ?? 0;
            $row['travel_ids'][] = (int) $trip->id;
            $row['_employments'][(int) $trip->employment_id] = true;
            unset($row);
        }

        $normalized = [];

        foreach ($rows as $row) {
            $employments = $row['_employments'];
            unset($row['_employments']);
            $row['employment_count'] = count($employments);
            $travelId = reset($row['travel_ids']);
            $row['detail_path'] = '/travel?tab=reports&travel_id='.($travelId === false ? '' : (string) $travelId);
            $normalized[] = $row;
        }

        usort(
            $normalized,
            static fn (array $a, array $b): int => [$b['month'], (string) $b['department_id']] <=> [$a['month'], (string) $a['department_id']],
        );

        return new TravelReport(
            rows: $normalized,
            total: count($normalized),
            asOf: $asOf,
            sourceHash: hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR)),
        );
    }

    private function inclusiveDays(BusinessTripRecord $trip): int
    {
        $from = CarbonImmutable::parse($trip->from_date->format('Y-m-d'), 'UTC');
        $to = CarbonImmutable::parse($trip->to_date->format('Y-m-d'), 'UTC');

        return max(0, (int) $from->diffInDays($to) + 1);
    }
}
