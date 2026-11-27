<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\Time\Contracts\TimeActor;
use Illuminate\Support\Facades\DB;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class CompanyProfileApiTest extends TimeHttpTestCase
{
    public function test_company_profile_is_single_company_read_and_update_with_audit(): void
    {
        $this->actAsHttp($this->settingsAdmin());

        $this->getJson('/v1/system/company-profile')
            ->assertOk()
            ->assertJsonPath('data.name', 'Synthetic Company')
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.legal_entity_id');

        $this->patchJson('/v1/system/company-profile', [
            'name' => '合成制造有限公司',
            'country_code' => 'CN',
            'timezone' => 'Asia/Shanghai',
            'currency_code' => 'CNY',
        ], ['Idempotency-Key' => 'cp-1'])
            ->assertOk()
            ->assertJsonPath('data.name', '合成制造有限公司');

        $this->assertSame(1, DB::table('company_profile')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'system.company_profile.updated']);
    }

    public function test_company_profile_rejects_an_invalid_timezone(): void
    {
        $this->actAsHttp($this->settingsAdmin());

        $this->patchJson('/v1/system/company-profile', [
            'name' => 'Synthetic Company',
            'country_code' => 'CN',
            'timezone' => 'Mars/Olympus',
            'currency_code' => 'CNY',
        ], ['Idempotency-Key' => 'cp-invalid'])
            ->assertUnprocessable();
    }

    public function test_company_profile_requires_settings_capability(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            userId: 7100,
            scope: 'COMPANY',
            capabilities: ['time.calendar.read'],
        ));

        $this->getJson('/v1/system/company-profile')->assertForbidden();
        $this->patchJson('/v1/system/company-profile', [
            'name' => 'Synthetic Company',
            'country_code' => 'CN',
            'timezone' => 'Asia/Shanghai',
            'currency_code' => 'CNY',
        ], ['Idempotency-Key' => 'cp-denied'])->assertForbidden();
    }

    private function settingsAdmin(): TimeActor
    {
        return TimeTestFixtures::actor(
            userId: 9001,
            employmentId: 1,
            scope: 'COMPANY',
            capabilities: ['system.settings.manage'],
        );
    }
}
