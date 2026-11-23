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
            'anchor_type' => AnchorType::LEGAL_ENTITY->value,
            'anchor_id' => 1,
            'anchor_key' => 1,
            'field_profile' => FieldProfile::P1_INTERNAL->value,
            'purpose_code' => 'test',
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addYear(),
            'source_type' => 'TEST',
            'source_id' => null,
            'auth_version' => 1,
            'created_at' => now(),
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
