<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Models\Person;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\LifecycleTestCase;

/**
 * Onboarding person data belongs to People. Lifecycle may reference it only
 * through an encrypted People-owned draft; Case, Intent and effect payloads
 * must never become a second plaintext PII store.
 */
final class OnboardingPiiRedactionTest extends LifecycleTestCase
{
    public function test_person_pii_is_encrypted_in_the_draft_and_redacted_from_case_intent_and_detail(): void
    {
        $org = $this->peopleScenario->orgUnit('PII-ONB-ORG');
        $pii = [
            'legal_name' => 'PII合成 员工',
            'id_number' => 'PII-SECRET-9911',
            'mobile' => '13900009911',
            'email' => 'pii.secret@example.test',
            'birth_date' => '1991-02-03',
        ];

        $created = $this->postJson('/v1/employees/onboard', [
            'person' => [
                'given_name' => 'PII合成',
                'family_name' => '员工',
                'national_id' => $pii['id_number'],
                'id_type' => 'CN_ID',
                'mobile' => $pii['mobile'],
                'email' => $pii['email'],
                'birth_date' => $pii['birth_date'],
            ],
            'employment' => [
                'employee_no' => 'PII-ONB-1',
                'hire_date' => '2026-12-01',
                'employment_type' => 'FULL_TIME',
            ],
            'assignment' => ['org_unit_id' => (int) $org->id],
            'terms' => [
                'salary_minor' => 1_200_000,
                'currency' => 'CNY',
                'work_location' => '上海',
                'work_schedule' => 'STANDARD',
            ],
        ], [
            'Idempotency-Key' => 'pii-onboarding-create',
            'X-CSRF-Token' => 'test-csrf',
        ])->assertStatus(202);

        $caseId = (int) $created->json('data.case_id');
        $draftId = (int) $created->json('data.onboarding_draft_id');
        $this->assertGreaterThan(0, $draftId);

        $rawCase = (string) DB::table('cases')->where('id', $caseId)->value('payload_json');
        $rawIntent = (string) DB::table('lifecycle_intents')->where('case_id', $caseId)->value('intent_json');
        $rawDraft = (string) DB::table('people_onboarding_drafts')->where('id', $draftId)->value('payload_ciphertext');

        $this->assertStringContainsString('onboarding_draft_id', $rawCase);
        $this->assertStringContainsString('onboarding_draft_id', $rawIntent);

        foreach ([$rawCase, $rawIntent, $rawDraft] as $raw) {
            $this->assertStringNotContainsString($pii['legal_name'], $raw);
            $this->assertStringNotContainsString($pii['id_number'], $raw);
            $this->assertStringNotContainsString($pii['mobile'], $raw);
            $this->assertStringNotContainsString($pii['email'], $raw);
            $this->assertStringNotContainsString($pii['birth_date'], $raw);
        }

        $this->assertDatabaseHas('people_onboarding_drafts', [
            'id' => $draftId,
            'case_id' => $caseId,
            'status' => 'PENDING',
        ]);

        $storedPerson = json_decode(Crypt::decryptString($rawDraft), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($pii['legal_name'], $storedPerson['legal_name']);
        $this->assertSame($pii['id_number'], $storedPerson['id_number']);
        $this->assertSame($pii['mobile'], $storedPerson['mobile']);
        $this->assertSame($pii['email'], $storedPerson['email']);
        $this->assertSame($pii['birth_date'], $storedPerson['birth_date']);

        $detail = $this->getJson("/v1/lifecycle/cases/{$caseId}")->assertOk();
        $detail->assertJsonPath('data.payload.onboarding_draft_id', $draftId);
        $detail->assertJsonMissingPath('data.payload.person');
        $this->assertPersonPiiAbsent((string) $detail->getContent(), $pii);

        $workflowDetail = $this->getJson("/v1/cases/{$caseId}")->assertOk();
        $workflowDetail->assertJsonPath('data.payload.onboarding_draft_id', $draftId);
        $this->assertPersonPiiAbsent((string) $workflowDetail->getContent(), $pii);

        $this->approveCase($caseId, 'pii-onboarding-approve')
            ->assertOk()
            ->assertJsonPath('data.effect_status', 'APPLIED');

        $person = Person::query()->firstOrFail();
        $this->assertSame($pii['legal_name'], $person->legal_name);
        $this->assertSame($pii['email'], $person->email);
        $this->assertSame(1, Employment::query()->count());
        $this->assertSame(1, Assignment::query()->count());
        $this->assertSame(1, EmploymentTerms::query()->count());

        $this->assertDatabaseHas('people_onboarding_drafts', [
            'id' => $draftId,
            'case_id' => $caseId,
            'status' => 'CONSUMED',
        ]);

        $this->assertStringNotContainsString($pii['id_number'], (string) DB::table('cases')->where('id', $caseId)->value('payload_json'));
        $this->assertStringNotContainsString($pii['mobile'], (string) DB::table('lifecycle_intents')->where('case_id', $caseId)->value('intent_json'));
        $this->assertStringNotContainsString($pii['email'], (string) DB::table('effects')->where('case_id', $caseId)->value('payload_json'));
    }

    public function test_canonical_lifecycle_onboarding_uses_the_same_encrypted_people_draft(): void
    {
        $org = $this->peopleScenario->orgUnit('PII-CANON-ORG');
        $pii = [
            'legal_name' => '规范入口 员工',
            'id_number' => 'PII-CANON-7788',
            'mobile' => '13900007788',
            'email' => 'canon.secret@example.test',
        ];

        $created = $this->postJson('/v1/lifecycle/onboarding', [
            'person' => [
                'legal_name' => $pii['legal_name'],
                'id_type' => 'CN_ID',
                'id_number' => $pii['id_number'],
                'mobile' => $pii['mobile'],
                'email' => $pii['email'],
            ],
            'employee_no' => 'PII-CANON-1',
            'org_unit_id' => (int) $org->id,
            'hire_date' => '2026-12-02',
            'employment_type' => 'FULL_TIME',
            'terms' => [
                'work_location' => '上海',
                'work_schedule' => 'STANDARD',
                'base_salary_minor' => 1_200_000,
                'salary_currency' => 'CNY',
            ],
        ], [
            'Idempotency-Key' => 'pii-canonical-create',
            'X-CSRF-Token' => 'test-csrf',
        ])->assertStatus(201);

        $caseId = (int) $created->json('data.case_id');
        $draftId = (int) $created->json('data.onboarding_draft_id');
        $this->assertGreaterThan(0, $draftId);
        $this->assertDatabaseHas('people_onboarding_drafts', [
            'id' => $draftId,
            'case_id' => $caseId,
            'status' => 'PENDING',
        ]);

        $rawCase = (string) DB::table('cases')->where('id', $caseId)->value('payload_json');
        $rawIntent = (string) DB::table('lifecycle_intents')->where('case_id', $caseId)->value('intent_json');

        foreach ([$rawCase, $rawIntent] as $raw) {
            $this->assertStringContainsString('onboarding_draft_id', $raw);
            $this->assertStringNotContainsString($pii['id_number'], $raw);
            $this->assertStringNotContainsString($pii['mobile'], $raw);
            $this->assertStringNotContainsString($pii['email'], $raw);
        }

        $detail = $this->getJson("/v1/lifecycle/cases/{$caseId}")->assertOk();
        $detail->assertJsonPath('data.payload.onboarding_draft_id', $draftId);
        $this->assertPersonPiiAbsent((string) $detail->getContent(), $pii);

        $this->approveCase($caseId, 'pii-canonical-approve')
            ->assertOk()
            ->assertJsonPath('data.effect_status', 'APPLIED');

        $this->assertSame($pii['legal_name'], Person::query()->firstOrFail()->legal_name);
        $this->assertDatabaseHas('people_onboarding_drafts', [
            'id' => $draftId,
            'status' => 'CONSUMED',
        ]);
    }

    /**
     * @param  array<string, string>  $pii
     */
    private function assertPersonPiiAbsent(string $body, array $pii): void
    {
        foreach ($pii as $value) {
            $this->assertStringNotContainsString($value, $body);
        }

        $this->assertStringNotContainsString('id_number', $body);
        $this->assertStringNotContainsString('mobile', $body);
        $this->assertStringNotContainsString('birth_date', $body);
    }
}
