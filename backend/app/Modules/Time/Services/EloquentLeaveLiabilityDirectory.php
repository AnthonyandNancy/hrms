<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\LeaveLiabilityDirectory;
use App\Modules\Time\Contracts\LeaveLiabilityRow;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeaveType;

final class EloquentLeaveLiabilityDirectory implements LeaveLiabilityDirectory
{
    /**
     * @return list<LeaveLiabilityRow>
     */
    public function balances(string $asOf): array
    {
        $balances = LeaveLedgerEntry::query()
            ->selectRaw('employment_id, leave_type_id, SUM(quantity_minutes) AS balance_minutes')
            ->where('effective_on', '<=', $asOf)
            ->groupBy('employment_id', 'leave_type_id')
            ->orderBy('employment_id')
            ->orderBy('leave_type_id')
            ->get();
        $types = LeaveType::query()
            ->get()
            ->keyBy('id');
        $rows = [];

        foreach ($balances as $balance) {
            $type = $types->get((int) $balance->leave_type_id);
            /** @var object{employment_id: int|string, leave_type_id: int|string, balance_minutes: int|string} $balance */
            $minutes = (int) $balance->balance_minutes;

            if ($type === null || $minutes === 0) {
                continue;
            }

            $rows[] = new LeaveLiabilityRow(
                employmentId: (int) $balance->employment_id,
                leaveTypeId: (int) $balance->leave_type_id,
                leaveTypeCode: (string) $type->code,
                leaveTypeName: (string) $type->name,
                balanceMinutes: $minutes,
            );
        }

        return $rows;
    }
}
