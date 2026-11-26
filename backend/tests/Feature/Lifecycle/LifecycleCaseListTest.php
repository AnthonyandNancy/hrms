<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\Lifecycle\Models\LifecycleIntent;
use Tests\Support\LifecycleTestCase;

final class LifecycleCaseListTest extends LifecycleTestCase
{
    public function test_lifecycle_cases_can_be_listed_with_type_filter(): void
    {
        $this->intent(101, 'ASSIGNMENT_CHANGE', 'employment', 1);

        $this->getJson('/v1/lifecycle/cases?case_type=ASSIGNMENT_CHANGE')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.case_type', 'ASSIGNMENT_CHANGE')
            ->assertJsonPath('data.items.0.case_id', 101);
    }

    public function test_lifecycle_cases_can_be_filtered_by_employment(): void
    {
        $this->intent(102, 'ASSIGNMENT_CHANGE', 'employment', 1);
        $this->intent(103, 'TERMS_CHANGE', 'employment', 2);

        $this->getJson('/v1/lifecycle/cases?employment_id=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employment_id', 1);

        $this->getJson('/v1/lifecycle/cases?employment_id=999')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_lifecycle_cases_are_company_wide(): void
    {
        $this->intent(104, 'ASSIGNMENT_CHANGE', 'employment', 1);
        $this->intent(105, 'TERMS_CHANGE', 'employment', 2);

        $this->getJson('/v1/lifecycle/cases')
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    private function intent(int $caseId, string $caseType, string $subjectType, int $employmentId): LifecycleIntent
    {
        return LifecycleIntent::query()->create([
            'case_id' => $caseId,
            'case_type' => $caseType,
            'status' => 'SUBMITTED',
            'subject_type' => $subjectType,
            'subject_id' => $employmentId,
            'intent_json' => [],
            'employment_id' => $employmentId,
            'recorded_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
        ]);
    }
}
