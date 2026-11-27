<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Domain\CaseTemplateCatalog;
use App\Modules\Lifecycle\Domain\LifecycleCaseType;
use App\Modules\Lifecycle\Domain\LifecycleValidator;
use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Modules\Workflow\Contracts\WorkflowCaseRecord;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates lifecycle business intent as a Workflow Case. This service never
 * mutates People state; the registered LOCAL effect is the only writer.
 */
final class LifecycleService
{
    public function __construct(
        private readonly WorkflowCaseGateway $workflow,
        private readonly LifecycleValidator $validator,
        private readonly CaseTemplateCatalog $templates,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{case:WorkflowCaseRecord,intent:LifecycleIntent,template_id:int}
     */
    public function createCase(
        int $requesterUserId,
        LifecycleCaseType $type,
        array $payload,
        string $businessDate,
        bool $submit,
    ): array {
        $this->validate($type, $payload);
        $template = $this->templateMetadata($type);

        return DB::transaction(function () use ($requesterUserId, $type, $payload, $businessDate, $submit, $template): array {
            $templateId = $this->workflow->ensureTemplate(
                code: (string) $template['code'],
                name: (string) $template['name'],
                definition: (array) $template['definition'],
            );

            $subjectType = match ($type) {
                LifecycleCaseType::ONBOARDING, LifecycleCaseType::REHIRE => isset($payload['person_id']) ? 'person' : 'intent',
                default => 'employment',
            };
            $subjectId = $subjectType === 'employment'
                ? (int) ($payload['employment_id'] ?? 0)
                : ($subjectType === 'person' ? (int) ($payload['person_id'] ?? 0) : null);

            // Template-by-code is represented by ensuring the code-owned
            // template and then creating against its published version.
            $case = $this->workflow->createCase(
                requesterUserId: $requesterUserId,
                type: $type->value,
                subjectType: $subjectType,
                subjectId: $subjectId !== null && $subjectId > 0 ? $subjectId : null,
                title: $this->title($type, $payload),
                payload: $payload,
                templateCode: (string) $template['code'],
                businessDate: $businessDate,
                submit: $submit,
            );

            $intent = LifecycleIntent::query()->create([
                'case_id' => $case->id,
                'case_type' => $type->value,
                'status' => LifecycleIntent::STATUS_DRAFT,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId !== null && $subjectId > 0 ? $subjectId : null,
                'intent_json' => $payload,
                'employment_id' => isset($payload['employment_id']) ? (int) $payload['employment_id'] : null,
                'recorded_at' => now('UTC'),
            ]);

            return [
                'case' => $case,
                'intent' => $intent,
                'template_id' => $templateId,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validate(LifecycleCaseType $type, array $payload): void
    {
        match ($type) {
            LifecycleCaseType::ONBOARDING, LifecycleCaseType::REHIRE => $this->validator->assertOnboarding($payload),
            default => $this->validator->assertEmployment($payload),
        };
    }

    /**
     * @return array{code:string,name:string,definition:array<string,mixed>,case_type:LifecycleCaseType}
     */
    private function templateMetadata(LifecycleCaseType $type): array
    {
        foreach ($this->templates->all() as $template) {
            if ($template['case_type'] === $type) {
                return $template;
            }
        }

        throw new ValidationException(['case_type' => ['No lifecycle template is registered.']]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function title(LifecycleCaseType $type, array $payload): string
    {
        return match ($type) {
            LifecycleCaseType::ONBOARDING => 'Onboard '.((string) ($payload['employee_no'] ?? 'new employee')),
            LifecycleCaseType::PROBATION_CONFIRM => 'Confirm probation for employment '.((string) ($payload['employment_id'] ?? '')),
            LifecycleCaseType::ASSIGNMENT_CHANGE => 'Assignment change for employment '.((string) ($payload['employment_id'] ?? '')),
            LifecycleCaseType::TERMS_CHANGE => 'Salary change for employment '.((string) ($payload['employment_id'] ?? '')),
            LifecycleCaseType::OFFBOARD => 'Offboard employment '.((string) ($payload['employment_id'] ?? '')),
            LifecycleCaseType::CONTRACT_RENEW => 'Renew contract for employment '.((string) ($payload['employment_id'] ?? '')),
            LifecycleCaseType::REHIRE => 'Rehire person '.((string) ($payload['person_id'] ?? '')),
        };
    }
}
