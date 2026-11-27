<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Modules\Time\Models\LeaveType;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use Carbon\CarbonImmutable;

/**
 * Read-only leave summary over real request projections and ledger entries.
 * It never fabricates entitlement, never reads Payroll tables and never writes
 * a materialised report fact.
 */
final class LeaveReportService
{
    public function __construct(private readonly BusinessCalendar $calendar) {}

    /**
     * @param  array{from: string, to: string, employment_id?: int|null, org_unit_id?: int|null, leave_type_id?: int|null}  $filters
     * @return array{rows: list<array<string, mixed>>, total: int, as_of: string, source_hash: string}
     */
    public function summary(TimeActor $actor, array $filters, string $asOf): array
    {
        $from = (string) $filters['from'];
        $to = (string) $filters['to'];

        if ($to < $from) {
            throw new ValidationException(['to' => ['The end date must not precede the start date.']]);
        }

        $query = LeaveRequestProjection::query()
            ->where('from_date', '<=', $to)
            ->where('to_date', '>=', $from);

        if ($actor->scope === 'SELF') {
            if ($actor->employmentId === null) {
                throw new NotFoundOrForbiddenException;
            }

            $query->where('employment_id', $actor->employmentId);
        } elseif ($actor->scope === 'MANAGED') {
            $query->whereIn('employment_id', $actor->managedEmploymentIds === [] ? [-1] : $actor->managedEmploymentIds);
        }

        if (($filters['employment_id'] ?? null) !== null) {
            $employmentId = (int) $filters['employment_id'];

            if (! $actor->canReadEmployment($employmentId)) {
                throw new NotFoundOrForbiddenException;
            }

            $query->where('employment_id', $employmentId);
        }

        if (($filters['leave_type_id'] ?? null) !== null) {
            $query->where('leave_type_id', (int) $filters['leave_type_id']);
        }

        if (($filters['org_unit_id'] ?? null) !== null) {
            $query->where('org_unit_id', (int) $filters['org_unit_id']);
        }

        $types = LeaveType::query()->get()->keyBy('id');
        $groups = [];
        $caseIds = [];

        foreach ($query->orderBy('id')->get() as $projection) {
            $split = $this->monthSplits($projection);

            foreach ($split as $month => $minutes) {
                $key = implode('|', [
                    (int) $projection->leave_type_id,
                    $projection->org_unit_id !== null ? (int) $projection->org_unit_id : 0,
                    $month,
                ]);
                $groups[$key] ??= [
                    'leave_type_id' => (int) $projection->leave_type_id,
                    'org_unit_id' => $projection->org_unit_id !== null ? (int) $projection->org_unit_id : null,
                    'month' => $month,
                    'requested_minutes' => 0,
                    'employment_ids' => [],
                    'case_ids' => [],
                ];
                $groups[$key]['requested_minutes'] += $minutes;
                $groups[$key]['employment_ids'][(int) $projection->employment_id] = true;
                $groups[$key]['case_ids'][(int) $projection->case_id] = true;
                $caseIds[(int) $projection->case_id] = true;
            }
        }

        $rows = [];

        foreach ($groups as $group) {
            $type = $types->get((int) $group['leave_type_id']);
            $employmentIds = array_keys($group['employment_ids']);
            sort($employmentIds);
            $month = (string) $group['month'];
            $start = $month.'-01';
            $end = CarbonImmutable::parse($start, 'UTC')->endOfMonth()->format('Y-m-d');
            $ledgerQuery = LeaveLedgerEntry::query()
                ->where('leave_type_id', (int) $group['leave_type_id'])
                ->whereIn('employment_id', $employmentIds)
                ->where('effective_on', '>=', $start)
                ->where('effective_on', '<=', $end);

            if ($group['org_unit_id'] !== null) {
                // The projection carries the department at request time; ledger
                // rows are scoped by the same employment set, never by a
                // Payroll/People table read.
            }

            $ledger = $ledgerQuery->get();
            $sum = LeaveEquation::summarize($ledger);

            $rows[] = [
                'leave_type_id' => (int) $group['leave_type_id'],
                'leave_type_code' => $type?->code,
                'leave_type_name' => $type?->name,
                'unit' => $type?->unit,
                'org_unit_id' => $group['org_unit_id'],
                'month' => $month,
                'employment_id' => count($employmentIds) === 1 ? $employmentIds[0] : null,
                'employment_ids' => $employmentIds,
                'requested_minutes' => (int) $group['requested_minutes'],
                'case_ids' => array_values(array_map('intval', array_keys($group['case_ids']))),
                'granted_minutes' => (int) $sum['granted'],
                // LeaveEquation exposes absolute account totals. Report
                // columns are "used / held / released" amounts, so keep those
                // unsigned and let balance_minutes carry the signed result.
                'reserved_minutes' => abs((int) $sum['reserved']),
                'consumed_minutes' => abs((int) $sum['consumed']),
                'released_minutes' => abs((int) $sum['released']),
                'reversed_minutes' => (int) $sum['reversed'],
                'balance_minutes' => (int) $sum['balance'],
            ];
        }

        usort($rows, static fn (array $left, array $right): int => [
            $left['month'],
            (int) ($left['org_unit_id'] ?? 0),
            (int) $left['leave_type_id'],
        ] <=> [
            $right['month'],
            (int) ($right['org_unit_id'] ?? 0),
            (int) $right['leave_type_id'],
        ]);

        $hash = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));

        return [
            'rows' => $rows,
            'total' => count($rows),
            'as_of' => $asOf,
            'source_hash' => $hash,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function monthSplits(LeaveRequestProjection $projection): array
    {
        $from = CarbonImmutable::parse($projection->from_date->format('Y-m-d'), 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($projection->to_date->format('Y-m-d'), 'UTC')->startOfDay();
        $minutes = (int) $projection->minutes;

        if ($from->format('Y-m') === $to->format('Y-m')) {
            return [$from->format('Y-m') => $minutes];
        }

        $workingByMonth = [];
        $cursor = $from;

        while ($cursor->lessThanOrEqualTo($to)) {
            $month = $cursor->format('Y-m');
            $workingByMonth[$month] = ($workingByMonth[$month] ?? 0)
                + ($this->calendar->isWorkingDay($cursor->format('Y-m-d')) ? 1 : 0);
            $cursor = $cursor->addDay();
        }

        $totalWorking = max(1, array_sum($workingByMonth));
        $splits = [];
        $allocated = 0;
        $months = array_keys($workingByMonth);

        foreach ($months as $index => $month) {
            if ($index === array_key_last($months)) {
                $splits[$month] = $minutes - $allocated;
                break;
            }

            $part = intdiv($minutes * $workingByMonth[$month], $totalWorking);
            $splits[$month] = $part;
            $allocated += $part;
        }

        return $splits;
    }
}
