<?php

declare(strict_types=1);

namespace App\Modules\Time\Console;

use App\Modules\Organization\Contracts\CompanyProfileReader;
use App\Modules\Time\Models\ShiftTemplate;
use App\Modules\Time\Services\BusinessCalendar;
use App\Modules\Time\Services\ShiftService;
use Illuminate\Console\Command;

/**
 * A company with no calendar cannot add holidays, create shifts or compute a
 * business date. This is the reference-data command the installer calls.
 */
final class SeedCalendarCommand extends Command
{
    protected $signature = 'time:seed-calendar
        {--timezone= : IANA timezone; defaults to the company profile timezone}
        {--workweek=1,2,3,4,5 : ISO weekdays worked}
        {--standard-day-minutes=480 : Standard working minutes per day}
        {--holidays-file= : CSV with date,name,type columns}';

    protected $description = 'Create the company calendar, a STANDARD shift and optional public holidays (idempotent)';

    public function handle(BusinessCalendar $calendar, ShiftService $shifts): int
    {
        $profile = app(CompanyProfileReader::class)->current();
        $timezone = (string) ($this->option('timezone') ?: $profile->timezone);
        $workweek = array_map('intval', explode(',', (string) $this->option('workweek')));
        $minutes = (int) $this->option('standard-day-minutes');

        $calendar->configure($timezone, $workweek, $minutes);
        $this->info("Company calendar configured ({$timezone}).");

        $existing = ShiftTemplate::query()
            ->where('code', 'STANDARD')
            ->exists();

        if (! $existing) {
            $shifts->createTemplate([
                'code' => 'STANDARD',
                'name' => 'Standard day',
                'start_local_time' => '09:00',
                'end_local_time' => '18:00',
                'break_minutes' => 60,
                'valid_from' => '2026-01-01',
                'valid_to' => '9999-12-31',
                'source_type' => 'INSTALL',
            ]);
            $this->info('STANDARD shift created.');
        }

        $file = $this->option('holidays-file');

        if (is_string($file) && $file !== '') {
            if (! is_file($file)) {
                $this->error("Holiday file not found: {$file}");

                return self::FAILURE;
            }

            $handle = fopen($file, 'rb');
            fgetcsv($handle);
            $count = 0;

            while (($row = fgetcsv($handle)) !== false) {
                [$date, $name, $type] = [$row[0] ?? '', $row[1] ?? '', $row[2] ?? 'PUBLIC'];

                if ($date === '') {
                    continue;
                }

                $calendar->addHoliday(
                    $date,
                    $name,
                    $type,
                    'INSTALL',
                );
                $count++;
            }

            fclose($handle);
            $this->info("{$count} holiday row(s) applied.");
        } else {
            $this->warn('No --holidays-file supplied: the calendar has no public holidays yet.');
        }

        return self::SUCCESS;
    }
}
