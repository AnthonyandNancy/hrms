<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\People\Models\Employment;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateConversion;
use App\Modules\Talent\Models\CandidateImport;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\Requisition;
use App\Modules\Talent\Models\RequisitionOpening;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SpySensitiveAccessAuditor;
use Tests\Support\TalentTestCase;

final class RecruitmentFunnelTest extends TalentTestCase
{
    private SpySensitiveAccessAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditor = new SpySensitiveAccessAuditor;
        $this->app->instance(SensitiveAccessAuditor::class, $this->auditor);
    }

    public function test_candidate_conversion_is_idempotent(): void
    {
        $candidate = $this->createAcceptedCandidate();
        $org = $this->peopleScenario->orgUnit('FUNNEL-CONVERSION-ORG');

        $first = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", [
            'org_unit_id' => $org->id,
            'employee_no' => 'CONV-0001',
        ], ['Idempotency-Key' => 'conv-1'])->assertCreated();

        $second = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", [
            'org_unit_id' => $org->id,
            'employee_no' => 'CONV-0001',
        ], ['Idempotency-Key' => 'conv-1'])->assertOk();

        $this->assertSame($first->json('data.employment_id'), $second->json('data.employment_id'));
        $this->assertSame(1, Employment::query()->where('employee_no', 'CONV-0001')->count());
    }

    public function test_candidate_conversion_is_business_idempotent_with_a_new_key_and_unique_candidate_constraint(): void
    {
        $candidate = $this->createAcceptedCandidate();
        $org = $this->peopleScenario->orgUnit('FUNNEL-CONVERSION-ORG-2');

        $first = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", [
            'org_unit_id' => $org->id,
            'employee_no' => 'CONV-0002',
        ], ['Idempotency-Key' => 'conv-new-key-1'])->assertCreated();

        $second = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", [
            'org_unit_id' => $org->id,
            'employee_no' => 'CONV-0002',
        ], ['Idempotency-Key' => 'conv-new-key-2'])->assertOk();

        $this->assertSame($first->json('data.employment_id'), $second->json('data.employment_id'));
        $this->assertSame(1, CandidateConversion::query()->where('candidate_id', $candidate->id)->count());

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('candidate_conversions')->insert([
            'candidate_id' => $candidate->id,
            'employment_id' => $first->json('data.employment_id'),
            'person_id' => $first->json('data.person_id'),
            'offer_id' => $first->json('data.offer_id'),
            'org_unit_id' => $org->id,
            'employee_no' => 'CONV-0002',
            'status' => 'COMPLETED',
            'recorded_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
    }

    public function test_a_requisition_must_be_approved_before_an_opening_can_be_published(): void
    {
        $org = $this->peopleScenario->orgUnit('FUNNEL-OPEN-ORG');
        $requisition = Requisition::query()->create([
            'org_unit_id' => $org->id,
            'requisition_no' => 'REQ-FUNNEL-OPEN',
            'title' => 'Synthetic opening role',
            'status' => 'PENDING_APPROVAL',
            'headcount' => 1,
            'recorded_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/recruitment/requisitions/{$requisition->id}/openings", [
            'channel' => 'EXTERNAL',
        ], ['Idempotency-Key' => 'opening-before-approval'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'REQUISITION_NOT_APPROVED');

        $requisition->forceFill(['status' => 'APPROVED'])->save();

        $this->postJson("/v1/recruitment/requisitions/{$requisition->id}/openings", [
            'channel' => 'EXTERNAL',
        ], ['Idempotency-Key' => 'opening-after-approval'])
            ->assertCreated()
            ->assertJsonPath('data.requisition_id', $requisition->id);

        $this->assertSame(1, RequisitionOpening::query()->where('requisition_id', $requisition->id)->count());
    }

    public function test_requisition_creation_persists_integer_minor_budget_and_reads_it_back(): void
    {
        $org = $this->peopleScenario->orgUnit('FUNNEL-BUDGET-ORG');

        $created = $this->postJson('/v1/recruitment/requisitions', [
            'org_unit_id' => $org->id,
            'requisition_no' => 'REQ-FUNNEL-BUDGET',
            'title' => 'Synthetic budget role',
            'headcount' => 2,
            'budget_minor' => 12000000,
            'budget_currency' => 'CNY',
        ], ['Idempotency-Key' => 'requisition-budget-1'])->assertCreated();

        $requisitionId = (int) $created->json('data.requisition_id');
        $this->assertSame(12000000, (int) Requisition::query()->findOrFail($requisitionId)->budget_minor);

        $this->getJson('/v1/recruitment/budget')
            ->assertOk()
            ->assertJsonPath('data.items.0.requisition_id', $requisitionId)
            ->assertJsonPath('data.items.0.budget_minor', 12000000)
            ->assertJsonPath('data.items.0.budget_currency', 'CNY')
            ->assertJsonPath('data.items.0.committed_offer_minor', 0);
    }

    public function test_candidate_import_returns_a_row_level_report_and_never_drops_rejected_rows(): void
    {
        $org = $this->peopleScenario->orgUnit('FUNNEL-IMPORT-ORG');
        $requisition = Requisition::query()->create([
            'org_unit_id' => $org->id,
            'requisition_no' => 'REQ-FUNNEL-IMPORT',
            'title' => 'Synthetic import role',
            'status' => 'APPROVED',
            'headcount' => 2,
            'budget_minor' => 12000000,
            'budget_currency' => 'CNY',
            'recorded_at' => $this->clock->now(),
        ]);

        $duplicate = Candidate::query()->create([
            'display_name' => 'Synthetic Existing',
            'email' => 'existing@example.test',
            'email_hash' => hash_hmac('sha256', 'existing@example.test', (string) config('hrms.pii_hmac_key')),
            'status' => 'NEW',
            'recorded_at' => $this->clock->now(),
        ]);

        $response = $this->postJson('/v1/recruitment/candidates/imports', [
            'requisition_id' => $requisition->id,
            'source' => 'CSV',
            'rows' => [
                ['display_name' => 'Synthetic Imported', 'email' => 'imported@example.test', 'source' => 'CSV'],
                ['display_name' => '', 'email' => 'missing-name@example.test'],
                ['display_name' => 'Synthetic Bad Email', 'email' => 'not-an-email'],
                ['display_name' => 'Synthetic Duplicate', 'email' => 'existing@example.test'],
            ],
        ], ['Idempotency-Key' => 'import-rows-1'])->assertCreated();

        $response->assertJsonPath('data.row_count', 4)
            ->assertJsonPath('data.accepted_count', 1)
            ->assertJsonPath('data.rejected_count', 3)
            ->assertJsonPath('data.rows.0.status', 'ACCEPTED')
            ->assertJsonPath('data.rows.1.status', 'REJECTED')
            ->assertJsonPath('data.rows.2.status', 'REJECTED')
            ->assertJsonPath('data.rows.3.status', 'REJECTED')
            ->assertJsonPath('data.rows.3.reason', 'CANDIDATE_DUPLICATE');

        $import = CandidateImport::query()->findOrFail((int) $response->json('data.import_id'));
        $this->assertSame(4, (int) $import->row_count);
        $this->assertCount(4, (array) $import->rows_json);
        $this->assertSame(1, Candidate::query()->where('display_name', 'Synthetic Imported')->count());
        $this->assertSame(0, Candidate::query()->where('display_name', 'Synthetic Bad Email')->count());
        $this->assertSame((int) $duplicate->id, (int) Candidate::query()->where('email_hash', $duplicate->email_hash)->firstOrFail()->id);
    }

    public function test_board_budget_and_reports_are_live_integer_minor_unit_projections(): void
    {
        $candidate = $this->createAcceptedCandidate('REQ-FUNNEL-BOARD', 18000000);
        $org = $this->peopleScenario->orgUnit('FUNNEL-BOARD-CONVERSION-ORG');
        $this->postJson("/v1/recruitment/candidates/{$candidate->id}/convert", [
            'org_unit_id' => $org->id,
            'employee_no' => 'CONV-BOARD-1',
        ], ['Idempotency-Key' => 'conv-board-1'])->assertCreated();

        $board = $this->getJson('/v1/recruitment/board')->assertOk()->assertJsonPath('data.freshness', 'LIVE');
        $board->assertJsonPath('data.items.0.requisition_no', 'REQ-FUNNEL-BOARD')
            ->assertJsonPath('data.items.0.hired_count', 1)
            ->assertJsonPath('data.items.0.headcount', 1);

        $budget = $this->getJson('/v1/recruitment/budget')->assertOk();
        $budget->assertJsonPath('data.items.0.budget_minor', 18000000)
            ->assertJsonPath('data.items.0.committed_offer_minor', 18000000)
            ->assertJsonPath('data.items.0.budget_variance_minor', 0)
            ->assertJsonPath('data.items.0.filled_headcount', 1)
            ->assertJsonPath('data.items.0.vacancy_count', 0);

        $reports = $this->getJson('/v1/recruitment/reports')->assertOk();
        $reports->assertJsonPath('data.items.0.requisition_no', 'REQ-FUNNEL-BOARD')
            ->assertJsonPath('data.items.0.fill_rate_bps', 10000)
            ->assertJsonPath('data.items.0.accepted_offers', 1)
            ->assertJsonPath('data.items.0.openings_count', 1);
    }

    public function test_candidate_list_is_pii_minimized_and_records_a_sensitive_read_audit(): void
    {
        $candidate = Candidate::query()->create([
            'display_name' => 'Synthetic Audit Candidate',
            'email' => 'audit@example.test',
            'email_hash' => hash_hmac('sha256', 'audit@example.test', (string) config('hrms.pii_hmac_key')),
            'mobile_ciphertext' => '+8613900000000',
            'status' => 'NEW',
            'recorded_at' => $this->clock->now(),
        ]);

        $response = $this->getJson('/v1/recruitment/candidates')->assertOk();
        $response->assertJsonPath('data.items.0.candidate_id', $candidate->id)
            ->assertJsonPath('data.items.0.has_email', true);
        $this->assertArrayNotHasKey('email', $response->json('data.items.0'));
        $this->assertArrayNotHasKey('mobile', $response->json('data.items.0'));

        $this->assertNotEmpty(array_filter(
            $this->auditor->records,
            static fn (array $record): bool => $record['resource_type'] === 'candidate'
                && $record['capability'] === 'recruitment.candidate.read',
        ));
    }

    public function test_offer_list_audits_each_p3_salary_read_without_exposing_other_pii(): void
    {
        $candidate = $this->createAcceptedCandidate('REQ-FUNNEL-OFFER-AUDIT', 7500000);

        $response = $this->getJson('/v1/recruitment/offers')->assertOk();
        $response->assertJsonPath('data.items.0.candidate_id', $candidate->id)
            ->assertJsonPath('data.items.0.base_salary_minor', 7500000);

        $offerAudits = array_values(array_filter(
            $this->auditor->records,
            static fn (array $record): bool => $record['resource_type'] === 'offer'
                && $record['capability'] === 'recruitment.offer.manage',
        ));

        $this->assertNotEmpty($offerAudits);
        $this->assertContains('base_salary_minor', $offerAudits[0]['fields']);
        $this->assertArrayNotHasKey('email', $response->json('data.items.0'));
    }

    public function test_evaluations_communications_and_talent_pool_round_trip_through_real_endpoints(): void
    {
        $candidate = $this->createAcceptedCandidate('REQ-FUNNEL-SUB', 9000000);

        $evaluation = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/evaluations", [
            'rating' => 4,
            'recommendation' => 'YES',
            'strengths' => 'Synthetic strengths',
            'concerns' => 'Synthetic concerns',
            'comments' => 'Synthetic evaluation',
        ], ['Idempotency-Key' => 'eval-1'])->assertCreated();

        $this->getJson("/v1/recruitment/candidates/{$candidate->id}/evaluations")
            ->assertOk()
            ->assertJsonPath('data.items.0.evaluation_id', $evaluation->json('data.evaluation_id'))
            ->assertJsonPath('data.items.0.rating', 4);

        $communication = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/communications", [
            'channel' => 'EMAIL',
            'direction' => 'OUTBOUND',
            'subject' => 'Synthetic subject',
            'body' => 'Synthetic communication body',
            'consent_basis' => 'APPLICATION',
        ], ['Idempotency-Key' => 'comm-1'])->assertCreated();

        $this->getJson("/v1/recruitment/candidates/{$candidate->id}/communications")
            ->assertOk()
            ->assertJsonPath('data.items.0.communication_id', $communication->json('data.communication_id'))
            ->assertJsonPath('data.items.0.channel', 'EMAIL');

        $tag = $this->postJson("/v1/recruitment/candidates/{$candidate->id}/talent-pool", [
            'tag' => 'FUTURE_FIT',
            'reason' => 'Synthetic pool reason',
            'retention_until' => '2027-12-31',
        ], ['Idempotency-Key' => 'tag-1'])->assertCreated();

        $this->getJson("/v1/recruitment/candidates/{$candidate->id}/talent-pool")
            ->assertOk()
            ->assertJsonPath('data.items.0.tag_id', $tag->json('data.tag_id'))
            ->assertJsonPath('data.items.0.tag', 'FUTURE_FIT');

        $this->assertNotEmpty(array_filter(
            $this->auditor->records,
            static fn (array $record): bool => in_array($record['resource_type'], ['candidate_evaluation', 'candidate_communication', 'talent_pool_tag'], true),
        ));
    }

    private function createAcceptedCandidate(string $requisitionNo = 'REQ-FUNNEL-CONV', int $salaryMinor = 2000000): Candidate
    {
        $org = $this->peopleScenario->orgUnit('FUNNEL-ORG-'.str_replace('-', '', $requisitionNo));
        $requisition = Requisition::query()->create([
            'org_unit_id' => $org->id,
            'requisition_no' => $requisitionNo,
            'title' => 'Synthetic funnel role',
            'status' => 'APPROVED',
            'headcount' => 1,
            'budget_minor' => $salaryMinor,
            'budget_currency' => 'CNY',
            'recorded_at' => $this->clock->now(),
        ]);

        $email = 'accepted-'.str_replace('-', '', $requisitionNo).'@example.test';
        $candidate = Candidate::query()->create([
            'display_name' => 'Synthetic Accepted Candidate',
            'email' => $email,
            'email_hash' => hash_hmac('sha256', $email, (string) config('hrms.pii_hmac_key')),
            'mobile_ciphertext' => '+8613800000001',
            'status' => 'OFFER_ACCEPTED',
            'recorded_at' => $this->clock->now(),
        ]);

        Offer::query()->create([
            'candidate_id' => (int) $candidate->id,
            'requisition_id' => (int) $requisition->id,
            'version' => 1,
            'status' => 'ACCEPTED',
            'base_salary_minor' => $salaryMinor,
            'salary_currency' => 'CNY',
            'start_date' => '2026-12-01',
            'accepted_at' => $this->clock->now(),
            'recorded_at' => $this->clock->now(),
        ]);

        Interview::query()->create([
            'candidate_id' => (int) $candidate->id,
            'requisition_id' => (int) $requisition->id,
            'scheduled_at' => '2026-11-20 09:00:00',
            'duration_minutes' => 60,
            'interviewer' => 'Synthetic Interviewer',
            'mode' => 'VIDEO',
            'status' => 'COMPLETED',
            'recommendation' => 'YES',
            'recorded_at' => $this->clock->now(),
        ]);

        RequisitionOpening::query()->create([
            'requisition_id' => (int) $requisition->id,
            'channel' => 'EXTERNAL',
            'status' => 'OPEN',
            'published_at' => $this->clock->now(),
            'recorded_at' => $this->clock->now(),
        ]);

        return $candidate;
    }
}
