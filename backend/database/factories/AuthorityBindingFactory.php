<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthorityBinding>
 */
class AuthorityBindingFactory extends Factory
{
    protected $model = AuthorityBinding::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'principal_id' => 1,
            'capability_code' => 'people.employee.read',
            'anchor_type' => AnchorType::COMPANY->value,
            'anchor_id' => null,
            'anchor_key' => 0,
            'field_profile' => FieldProfile::P1_INTERNAL->value,
            'purpose_code' => 'test',
            // Validity must follow the injected clock, not the wall clock.
            // Authorization looks bindings up at `businessDate 00:00:00`, so a
            // wall-clock "now minus one day" can land after that instant and
            // make an otherwise valid binding look expired. That made these
            // tests depend on the time of day they were run.
            'valid_from' => app(\App\Shared\Time\Clock::class)->now()->subDay()->startOfDay()->utc(),
            'valid_to' => app(\App\Shared\Time\Clock::class)->now()->addYear()->utc(),
            'source_type' => 'TEST',
            'source_id' => null,
            'auth_version' => 1,
            'created_at' => app(\App\Shared\Time\Clock::class)->now()->utc(),
        ];
    }

    public function forPrincipal(int $tenantId, int $userId, int $authVersion = 1): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenantId,
            'principal_id' => $userId,
            'auth_version' => $authVersion,
        ]);
    }

    public function anchor(AnchorType $anchorType, ?int $anchorId, FieldProfile $profile = FieldProfile::P1_INTERNAL): static
    {
        return $this->state(fn (): array => [
            'anchor_type' => $anchorType->value,
            'anchor_id' => $anchorId,
            'anchor_key' => $anchorId ?? 0,
            'field_profile' => $profile->value,
        ]);
    }
}
