<?php

declare(strict_types=1);

namespace App\Modules\People\Commands;

use App\Shared\Time\BusinessDate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PeopleAsOfCheckCommand extends Command
{
    protected $signature = 'people:as-of-check {--date= : Business date to check}';

    protected $description = 'Detect overlapping PRIMARY assignments and assignments outside employment validity';

    public function handle(): int
    {
        $date = $this->option('date');

        if (! is_string($date) || BusinessDate::tryFrom($date) === null) {
            $this->error('--date must be a real calendar date in YYYY-MM-DD format.');

            return self::FAILURE;
        }

        $violations = 0;
        $primaries = DB::table('assignments')
            ->where('valid_from', '<=', $date)
            ->where('valid_to', '>', $date)
            ->where(function ($query): void {
                $query->where('assignment_type', 'PRIMARY')->orWhere('is_primary', true);
            })
            ->select(['id', 'tenant_id', 'employment_id', 'valid_from', 'valid_to'])
            ->orderBy('employment_id')
            ->orderBy('id')
            ->get();

        $byEmployment = [];

        foreach ($primaries as $primary) {
            $key = $primary->tenant_id.':'.$primary->employment_id;

            if (isset($byEmployment[$key])) {
                $violations++;
                $this->error("Overlapping PRIMARY assignments for employment {$primary->employment_id}: {$byEmployment[$key]} and {$primary->id}.");
            }

            $byEmployment[$key] = $primary->id;
        }

        $orphans = DB::table('assignments')
            ->leftJoin('employments', 'employments.id', '=', 'assignments.employment_id')
            ->whereNull('employments.id')
            ->count();

        if ($orphans > 0) {
            $violations += (int) $orphans;
            $this->error("Found {$orphans} orphan assignment(s).");
        }

        if ($violations > 0) {
            return self::FAILURE;
        }

        $this->info("No assignment timeline violations on {$date}.");

        return self::SUCCESS;
    }
}
