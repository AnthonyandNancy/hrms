<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Tests\Feature\Authorization\AuthorizationTestSupport;

final class CompanyAnchorTest extends AuthorizationTestSupport
{
    public function test_company_anchor_is_the_only_data_wide_anchor_and_needs_no_id(): void
    {
        $this->assertSame('COMPANY', AnchorType::COMPANY->value);
        $this->assertFalse(AnchorType::COMPANY->requiresAnchorId());
        $this->assertFalse(defined(AnchorType::class.'::LEGAL_ENTITY'));
    }

    public function test_a_company_wildcard_binding_authorizes_a_capability_without_an_anchor_id(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, '*', AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED);

        $allowed = app(\App\Shared\Authorization\Authorizer::class)->allows(
            $this->actingAsUser($user),
            'people.employee.read',
            new AnchorRef(AnchorType::COMPANY, null),
            '2026-11-23',
        );

        $this->assertTrue($allowed);
    }

    public function test_a_company_anchor_is_not_satisfied_by_an_organization_binding(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, 'people.employee.read', AnchorType::ORG_UNIT, 10, FieldProfile::P1_INTERNAL);

        $allowed = app(\App\Shared\Authorization\Authorizer::class)->allows(
            $this->actingAsUser($user),
            'people.employee.read',
            new AnchorRef(AnchorType::COMPANY, null),
            '2026-11-23',
        );

        $this->assertFalse($allowed);
    }

    private function grant(User $user, string $capability, AnchorType $anchor, ?int $anchorId, FieldProfile $profile): void
    {
        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id, (int) $user->auth_version)
            ->anchor($anchor, $anchorId, $profile)
            ->create([
                'capability_code' => $capability,
                'purpose_code' => 'TEST',
                'valid_from' => '2026-01-01 00:00:00',
                'valid_to' => '2027-01-01 00:00:00',
            ]);
    }
}
