<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Models\AuditEvent;
use App\Modules\Time\Contracts\TimeActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

/**
 * Volume 4 Task 5: the settings surfaces are verified against their real
 * endpoints. The suite exercises the same Time actor seam as the Time HTTP
 * suite so route capability checks and the calendar projection cannot drift.
 */
final class SettingsWorkspaceTest extends TimeHttpTestCase
{
    public function test_calendar_publish_is_single_company_and_audited(): void
    {
        $this->actAsHttp($this->settingsAdmin());

        $this->postJson('/v1/system/calendars/publish', [
            'timezone' => 'Asia/Shanghai',
            'workweek' => [1, 2, 3, 4, 5],
            'standard_day_minutes' => 480,
            'holidays' => [
                ['date' => '2027-10-01', 'name' => 'Synthetic National Day', 'type' => 'PUBLIC'],
            ],
        ], ['Idempotency-Key' => 'calendar-publish-single-company'])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Shanghai')
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.legal_entity_id');

        $this->assertFalse(Schema::hasTable('legal_entities'));
        $this->assertFalse(Schema::hasColumn('legal_entity_calendars', 'legal_entity_id'));
        $this->assertSame(1, DB::table('legal_entity_calendars')->count());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'time.calendar.published',
            'aggregate_type' => 'company_calendar',
        ]);

        // The chosen-year projection is the only calendar read endpoint and it
        // returns a single company snapshot, never a tenant/legal-entity key.
        $this->getJson('/v1/time/calendars?year=2027')
            ->assertOk()
            ->assertJsonPath('data.year', 2027)
            ->assertJsonPath('data.holidays.0.name', 'Synthetic National Day')
            ->assertJsonMissingPath('data.legal_entity_id')
            ->assertJsonMissingPath('data.tenant_id');
    }

    public function test_dictionary_parameter_and_feature_flag_writes_emit_audit_actions(): void
    {
        $this->actAsHttp($this->settingsAdmin());

        $dictionaryId = (int) $this->postJson('/v1/system/dictionaries', [
            'type' => 'EMPLOYEE_STATUS',
            'code' => 'PROBATION',
            'label' => '试用',
            'sort_order' => 2,
            'active' => true,
        ], ['Idempotency-Key' => 'settings-dictionary-create'])->assertCreated()->json('data.id');

        $this->patchJson('/v1/system/dictionaries/'.$dictionaryId, [
            'label' => '试用期',
            'active' => false,
        ], ['Idempotency-Key' => 'settings-dictionary-update'])
            ->assertOk()
            ->assertJsonPath('data.label', '试用期');

        $this->putJson('/v1/system/parameters', [
            'key' => 'pagination.max_page_size',
            'scope' => 'COMPANY',
            'value' => 80,
            'sensitive' => false,
        ], ['Idempotency-Key' => 'settings-parameter-update'])
            ->assertOk()
            ->assertJsonPath('data.key', 'pagination.max_page_size')
            ->assertJsonPath('data.value', 80);

        $this->putJson('/v1/platform/features/export_enabled', [
            'enabled' => true,
            'reason' => 'Synthetic release window approved',
        ])->assertOk()->assertJsonPath('data.enabled', true);

        $this->assertDatabaseHas('audit_events', ['action' => 'system.dictionary.created']);
        $this->assertDatabaseHas('audit_events', ['action' => 'system.dictionary.updated']);
        $this->assertDatabaseHas('audit_events', ['action' => 'system.parameter.updated']);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'feature_flag.changed',
            'aggregate_id' => 'export_enabled',
        ]);
        $this->assertDatabaseMissing('sys_parameters', [
            'key' => 'invented.unregistered.setting',
        ]);
    }

    public function test_benefit_rates_are_decimal_strings_and_plan_creation_is_audited(): void
    {
        $employmentId = $this->syntheticEmployment();
        $this->actAsHttp($this->settingsAdmin(userId: 1001));

        $plan = $this->postJson('/v1/benefits/plans', [
            'code' => 'SOCIAL_INSURANCE',
            'name' => '社会保险',
            'employer_rate' => '0.160000',
            'employee_rate' => '0.080000',
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'settings-benefit-plan'])
            ->assertCreated()
            ->assertJsonPath('data.employer_rate', '0.160000')
            ->assertJsonPath('data.employee_rate', '0.080000')
            ->json('data');

        $this->postJson('/v1/benefits/enrollments', [
            'plan_id' => (int) $plan['id'],
            'employment_id' => $employmentId,
            'base_amount_cents' => 1_000_000,
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'settings-benefit-enrollment'])
            ->assertCreated()
            ->assertJsonPath('data.employee_contribution_cents', 80_000)
            ->assertJsonPath('data.employer_contribution_cents', 160_000);

        // The plan list exposes exact strings; the filtered enrolment list
        // exposes the count the UI can show without inventing a per-plan field.
        $this->getJson('/v1/benefits/plans')
            ->assertOk()
            ->assertJsonFragment(['employer_rate' => '0.160000', 'employee_rate' => '0.080000']);

        $this->getJson('/v1/benefits/enrollments?plan_id='.(int) $plan['id'].'&page=1&page_size=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'benefit.plan.versioned',
            'aggregate_type' => 'benefit_plan',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'benefit.enrollment.versioned',
            'aggregate_type' => 'benefit_enrollment',
        ]);
        $this->assertSame(
            '0.160000',
            (string) DB::table('benefit_plans')->where('code', 'SOCIAL_INSURANCE')->value('employer_rate'),
        );
    }

    public function test_settings_write_routes_reject_callers_without_the_route_capability(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            userId: 7001,
            scope: 'COMPANY',
            capabilities: ['time.calendar.read'],
        ));

        $this->postJson('/v1/system/calendars/publish', [
            'timezone' => 'Asia/Shanghai',
            'workweek' => [1, 2, 3, 4, 5],
        ], ['Idempotency-Key' => 'calendar-denied'])
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->putJson('/v1/system/parameters', [
            'key' => 'pagination.max_page_size',
            'scope' => 'COMPANY',
            'value' => 10,
        ], ['Idempotency-Key' => 'parameter-denied'])
            ->assertForbidden();

        $this->assertSame(0, AuditEvent::query()->whereIn('action', [
            'time.calendar.published',
            'system.parameter.updated',
        ])->count());
    }

    private function settingsAdmin(int $userId = 9001): TimeActor
    {
        return TimeTestFixtures::actor(
            userId: $userId,
            employmentId: 1,
            scope: 'COMPANY',
            capabilities: [
                'time.calendar.read',
                'time.calendar.manage',
                'system.settings.manage',
                'system.dictionary.manage',
                'system.parameter.manage',
                'platform.feature.manage',
                'benefit.read',
                'benefit.manage',
            ],
        );
    }

    private function syntheticEmployment(): int
    {
        $personId = (int) DB::table('persons')->insertGetId([
            'legal_name' => 'Synthetic Settings Employee',
            'birth_date' => '1990-01-01',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return (int) DB::table('employments')->insertGetId([
            'person_id' => $personId,
            'employee_no' => 'E-SETTINGS',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'hire_date' => '2026-01-01',
            'seniority_start_date' => '2026-01-01',
            'recorded_at' => now('UTC'),
            'source_type' => 'TEST',
        ]);
    }
}
