<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\Organization\Models\LegalEntity;
use App\Modules\Time\Models\Holiday;
use App\Modules\Time\Models\LegalEntityCalendar;
use App\Modules\Time\Models\ShiftTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeedCalendarCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_calendar_a_standard_shift_and_the_holidays_in_the_file(): void
    {
        $entity = $this->legalEntity();

        $file = tempnam(sys_get_temp_dir(), 'holidays').'.csv';
        file_put_contents($file, "date,name,type\n2027-01-01,New Year,PUBLIC\n2027-02-06,Spring Festival,PUBLIC\n");

        $this->artisan('time:seed-calendar', [
            '--legal-entity' => (int) $entity->id,
            '--timezone' => 'Asia/Shanghai',
            '--workweek' => '1,2,3,4,5',
            '--standard-day-minutes' => 480,
            '--holidays-file' => $file,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('legal_entity_calendars', ['legal_entity_id' => (int) $entity->id]);
        $this->assertDatabaseHas('holidays', ['legal_entity_id' => (int) $entity->id, 'date' => '2027-01-01']);
        $this->assertSame(2, Holiday::query()->count());
        $this->assertDatabaseHas('shift_templates', ['legal_entity_id' => (int) $entity->id, 'code' => 'STANDARD']);

        @unlink($file);
    }

    public function test_it_is_idempotent(): void
    {
        $entity = $this->legalEntity();

        foreach ([1, 2] as $_) {
            $this->artisan('time:seed-calendar', [
                '--legal-entity' => (int) $entity->id,
                '--timezone' => 'Asia/Shanghai',
                '--workweek' => '1,2,3,4,5',
                '--standard-day-minutes' => 480,
            ])->assertExitCode(0);
        }

        $this->assertSame(1, LegalEntityCalendar::query()->count());
        $this->assertSame(1, ShiftTemplate::query()->count());
    }

    public function test_it_fails_when_the_holiday_file_is_missing(): void
    {
        $entity = $this->legalEntity();

        $this->artisan('time:seed-calendar', [
            '--legal-entity' => (int) $entity->id,
            '--timezone' => 'Asia/Shanghai',
            '--holidays-file' => '/definitely/not/here.csv',
        ])->assertExitCode(1);
    }

    private function legalEntity(): LegalEntity
    {
        return LegalEntity::query()->create([
            'tenant_id' => 1,
            'code' => 'ACME-CN',
            'name' => 'Acme China',
            'country_code' => 'CN',
            'timezone' => 'Asia/Shanghai',
            'currency_code' => 'CNY',
            'status' => 'ACTIVE',
        ]);
    }
}
