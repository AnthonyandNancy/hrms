<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Contracts\OnboardingCaseCommand;
use App\Modules\Lifecycle\Domain\CaseTemplateCatalog;
use App\Modules\Lifecycle\Domain\LifecycleCaseType;
use App\Modules\Lifecycle\Domain\LifecycleEffectiveDate;
use App\Modules\Lifecycle\Domain\LifecycleValidator;
use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\Platform\Contracts\IdempotencyRunner;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Modules\Workflow\Contracts\WorkflowCaseRecord;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Creates lifecycle business intent as a Workflow Case. This service never
 * mutates People state; the registered LOCAL effect is the only writer.
 */
final class LifecycleService implements OnboardingCaseCommand
{
    public function __construct(
        private readonly WorkflowCaseGateway $workflow,
        private readonly LifecycleValidator $validator,
        private readonly CaseTemplateCatalog $templates,
        private readonly AuditRecorder $audit,
        private readonly IdempotencyRunner $idempotency,
    ) {}

    public function submit(int $actorUserId, array $payload, string $idempotencyKey, string $businessDate): array
    {
        $key = trim($idempotencyKey);

        if ($key === '' || mb_strlen($key) > 128) {
            throw new ValidationException(
                ['idempotency_key' => ['A valid Idempotency-Key header is required (1-128 characters).']],
                'A valid Idempotency-Key header is required.',
                ['reason' => 'IDEMPOTENCY_KEY_REQUIRED'],
            );
        }

        $result = DB::transaction(fn (): \App\Modules\Platform\Contracts\IdempotentResult => $this->idempotency->run(
            principalId: $actorUserId,
            scope: 'CMD:lifecycle.onboarding.create',
            key: $key,
            payload: $payload,
            operation: function () use ($actorUserId, $payload, $businessDate): array {
                $created = $this->createCase(
                    requesterUserId: $actorUserId,
                    type: LifecycleCaseType::ONBOARDING,
                    payload: $payload,
                    businessDate: $businessDate,
                    submit: (bool) ($payload['submit'] ?? true),
                );
                $case = $created['case'];

                return [
                    'http_status' => 201,
                    'body' => [
                        'case_id' => $case->id,
                        'case_no' => $case->caseNo,
                        'case_type' => $case->type,
                        'application_status' => $case->applicationStatus,
                        'effect_status' => $case->effectStatus,
                        'canonical_endpoint' => '/v1/lifecycle/onboarding',
                    ],
                    'resource_type' => 'case',
                    'resource_id' => (string) $case->id,
                ];
            },
        ));

        return [...$result->body, 'replayed' => $result->replayed];
    }

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
        $plannedEffectiveOn = LifecycleEffectiveDate::forPayload($type->value, $payload);
        $deduplicationKey = $this->deduplicationKey($type, $payload);

        return DB::transaction(function () use ($requesterUserId, $type, $payload, $businessDate, $submit, $template, $plannedEffectiveOn, $deduplicationKey): array {
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
                'offer_id' => isset($payload['offer_id']) ? (int) $payload['offer_id'] : null,
                'employment_id' => isset($payload['employment_id']) ? (int) $payload['employment_id'] : null,
                'planned_effective_on' => $plannedEffectiveOn,
                'deduplication_key' => $deduplicationKey,
                'recorded_at' => now('UTC'),
            ]);

            $this->audit->record(
                actorUserId: $requesterUserId,
                action: 'lifecycle.case.created',
                aggregateType: 'lifecycle_intent',
                aggregateId: (int) $intent->id,
                after: [
                    'case_id' => (int) $case->id,
                    'case_no' => $case->caseNo,
                    'case_type' => $type->value,
                    'application_status' => $case->applicationStatus,
                    'planned_effective_on' => $plannedEffectiveOn,
                    'deduplication_key' => $deduplicationKey,
                    'submitted' => $submit,
                ],
            );

            return [
                'case' => $case,
                'intent' => $intent,
                'template_id' => $templateId,
            ];
        });
    }

    /**
     * Stable intent fingerprint used for evidence and duplicate detection.
     * The HTTP idempotency key still owns request replay; this value explains
     * which business intent a case belongs to without storing free-text PII.
     *
     * @param  array<string, mixed>  $payload
     */
    private function deduplicationKey(LifecycleCaseType $type, array $payload): ?string
    {
        $identity = match ($type) {
            LifecycleCaseType::ONBOARDING, LifecycleCaseType::REHIRE => [
                'person_id' => $payload['person_id'] ?? null,
                'candidate_id' => $payload['candidate_id'] ?? null,
                'previous_employment_id' => $payload['previous_employment_id'] ?? null,
                'employee_no' => $payload['employee_no'] ?? null,
            ],
            LifecycleCaseType::OFFBOARD => [
                'employment_id' => $payload['employment_id'] ?? null,
                'last_working_date' => $payload['last_working_date'] ?? null,
                'termination_type' => $payload['termination_type'] ?? null,
            ],
            default => [
                'employment_id' => $payload['employment_id'] ?? null,
                'effective_date' => $payload['effective_date'] ?? $payload['effective_from'] ?? null,
                'org_unit_id' => $payload['org_unit_id'] ?? null,
                'base_salary_minor' => $payload['base_salary_minor'] ?? null,
            ],
        };

        if (array_filter($identity, static fn (mixed $value): bool => $value !== null && $value !== '') === []) {
            return null;
        }

        return hash('sha256', $type->value.'|'.json_encode($identity, JSON_THROW_ON_ERROR));
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
