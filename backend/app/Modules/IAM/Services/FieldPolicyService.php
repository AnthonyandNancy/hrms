<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Authorization\SensitiveFieldCatalog;
use App\Modules\IAM\Models\FieldPolicy;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class FieldPolicyService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return list<array{field_key: string, profile: string, declared_profile: string, can_tighten: bool}>
     */
    public function rows(): array
    {
        $stored = FieldPolicy::query()
            ->get()
            ->keyBy('field_key');

        $rows = [];

        foreach (SensitiveFieldCatalog::fields() as $fieldKey => $declared) {
            $policy = $stored->get($fieldKey);
            $effective = $policy !== null ? FieldProfile::from((string) $policy->field_profile) : $declared;

            $rows[] = [
                'field_key' => $fieldKey,
                'profile' => $effective->value,
                'declared_profile' => $declared->value,
                'can_tighten' => $effective->rank() < $declared->rank(),
            ];
        }

        return $rows;
    }

    /**
     * @return array{field_key: string, profile: string, declared_profile: string, can_tighten: bool}
     */
    public function update(string $fieldKey, string $profile): array
    {
        $declared = SensitiveFieldCatalog::declared($fieldKey);

        if ($declared === null) {
            throw new ValidationException(
                ['field_key' => ['The field is not declared in the code-owned sensitive field catalog.']],
                'The given data was invalid.',
                ['reason' => 'UNKNOWN_FIELD_POLICY'],
            );
        }

        $requested = FieldProfile::tryFrom($profile);

        if ($requested === null) {
            throw new ValidationException(
                ['field_profile' => ['Unknown field profile.']],
                'The given data was invalid.',
                ['reason' => 'UNKNOWN_FIELD_PROFILE'],
            );
        }

        // A policy may only tighten: rank grows as sensitivity increases, so a
        // requested rank lower than the declared one would reveal more.
        if ($requested->rank() < $declared->rank()) {
            throw new ConflictException(
                'Field policies can only tighten visibility; widening is rejected.',
                ['reason' => 'FIELD_POLICY_WIDENING_REJECTED'],
            );
        }

        return DB::transaction(function () use ($fieldKey, $requested, $declared): array {
            /** @var FieldPolicy|null $existing */
            $existing = FieldPolicy::query()
                ->where('field_key', $fieldKey)
                ->first();

            $before = $existing === null ? [] : ['profile' => (string) $existing->field_profile];

            /** @var FieldPolicy $policy */
            $policy = FieldPolicy::query()->updateOrCreate(
                ['field_key' => $fieldKey],
                ['field_profile' => $requested->value],
            );

            $this->audit->record(
                actorUserId: \App\Shared\RequestContext::userId(),
                action: 'iam.field_policy.updated',
                aggregateType: 'field_policy',
                aggregateId: $fieldKey,
                before: $before,
                after: ['profile' => (string) $policy->field_profile],
                metadata: ['request_id' => \App\Shared\RequestContext::requestId()],
            );

            return [
                'field_key' => $fieldKey,
                'profile' => (string) $policy->field_profile,
                'declared_profile' => $declared->value,
                'can_tighten' => $requested->rank() < $declared->rank(),
            ];
        });
    }
}
