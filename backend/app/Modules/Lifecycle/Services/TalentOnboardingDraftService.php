<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Contracts\TalentOnboardingDraftPort;
use App\Modules\Lifecycle\Domain\LifecycleCaseType;
use App\Modules\Lifecycle\Models\LifecycleIntent;
use Illuminate\Support\Facades\DB;

final class TalentOnboardingDraftService implements TalentOnboardingDraftPort
{
    public function __construct(
        private readonly LifecycleService $lifecycle,
    ) {}

    public function createDraftFromAcceptedOffer(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $existing = LifecycleIntent::query()
                ->where('case_type', LifecycleCaseType::ONBOARDING->value)
                ->where('intent_json->candidate_id', (int) $input['candidate_id'])
                ->where('intent_json->offer_id', (int) $input['offer_id'])
                ->first();

            if ($existing !== null) {
                return ['case_id' => (int) $existing->case_id, 'replayed' => true];
            }

            $created = $this->lifecycle->createCase(
                requesterUserId: (int) $input['actor_user_id'],
                type: LifecycleCaseType::ONBOARDING,
                payload: [
                    'candidate_id' => (int) $input['candidate_id'],
                    'offer_id' => (int) $input['offer_id'],
                    'person' => is_array($input['person'] ?? null) ? $input['person'] : [],
                    'org_unit_id' => (int) $input['org_unit_id'],
                    'employee_no' => (string) $input['employee_no'],
                    'hire_date' => (string) $input['hire_date'],
                    'employment_type' => 'FULL_TIME',
                    'terms' => [
                        'work_location' => 'Unspecified',
                        'work_schedule' => 'STANDARD',
                        'base_salary_minor' => $this->nullableInt($input['terms']['base_salary_minor'] ?? null),
                        'salary_currency' => is_string($input['terms']['salary_currency'] ?? null)
                            ? strtoupper((string) $input['terms']['salary_currency'])
                            : null,
                        'salary_scheme_code' => is_string($input['terms']['salary_scheme_code'] ?? null)
                            ? (string) $input['terms']['salary_scheme_code']
                            : null,
                    ],
                ],
                businessDate: (string) $input['business_date'],
                submit: false,
            );

            return ['case_id' => $created['case']->id, 'replayed' => false];
        });
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && preg_match('/^\d+$/D', $value) === 1)
            ? (int) $value
            : null;
    }
}
