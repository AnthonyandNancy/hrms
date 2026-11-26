<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;

/**
 * Regression for the production incident where the installer bound a role at
 * 00:42 and every capability route returned 403 for the rest of that business
 * day: bindings were written with an instant but read from the day's midnight.
 */
final class BindingValidityHttpTest extends AuthorizationTestSupport
{
    public function test_a_binding_written_just_after_midnight_authorizes_the_same_business_day(): void
    {
        $this->useClock('2026-11-25T00:42:38Z');

        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, AnchorType::COMPANY, null, [
            'valid_from' => '2026-11-25 00:42:38',
            'valid_to' => '2027-11-26 00:42:38',
        ]);

        $principal = $this->actingAsUser($user);
        $date = '2026-11-25';

        $authorizer = app(CapabilityAuthorizer::class);

        $this->assertTrue($authorizer->permits($principal, 'org.tree.read', $date));
        $this->assertTrue($authorizer->allows(
            $principal,
            'org.tree.read',
            new AnchorRef(AnchorType::COMPANY, null),
            $date,
        ));
    }

    public function test_a_binding_that_expires_at_day_start_is_not_effective_that_day(): void
    {
        $this->useClock('2026-11-25T09:00:00Z');

        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, AnchorType::COMPANY, null, [
            'valid_from' => '2026-11-01 09:00:00',
            'valid_to' => '2026-11-25 00:00:00',
        ]);

        $this->assertFalse(app(CapabilityAuthorizer::class)->permits(
            $this->actingAsUser($user),
            'org.tree.read',
            '2026-11-25',
        ));
    }

    public function test_the_binding_that_expires_at_day_start_was_effective_the_day_before(): void
    {
        $this->useClock('2026-11-24T09:00:00Z');

        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, AnchorType::COMPANY, null, [
            'valid_from' => '2026-11-01 09:00:00',
            'valid_to' => '2026-11-25 00:00:00',
        ]);

        $this->assertTrue(app(CapabilityAuthorizer::class)->permits(
            $this->actingAsUser($user),
            'org.tree.read',
            '2026-11-24',
        ));
    }

    private function useClock(string $instant): void
    {
        $this->clock = new FrozenClock($instant);
        $this->app->instance(Clock::class, $this->clock);
        $this->app->forgetInstance(CapabilityAuthorizer::class);
    }

    /** @param array<string, string> $overrides */
    private function grant(User $user, AnchorType $anchor, ?int $anchorId, array $overrides = []): void
    {
        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, (int) $user->auth_version)
            ->anchor($anchor, $anchorId, FieldProfile::P3_RESTRICTED)
            ->create([
                'capability_code' => CapabilityWildcard::WILDCARD,
                ...$overrides,
            ]);
    }
}
