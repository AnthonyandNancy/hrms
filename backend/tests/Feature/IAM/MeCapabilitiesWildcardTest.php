<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Auth\AuthenticatedPrincipal;
use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MeCapabilitiesWildcardTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock('2026-11-24T09:00:00Z');
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_a_wildcard_holder_sees_every_catalogued_capability_with_the_wildcard_profile(): void
    {
        $user = User::factory()->create(['tenant_id' => 1, 'auth_version' => 1]);
        $wildcardBinding = $this->grantCapability(
            $user,
            CapabilityWildcard::WILDCARD,
            'LEGAL_ENTITY',
            1,
            'P3_RESTRICTED',
            purposeCode: 'SYSTEM_ADMIN',
        );

        $before = $this->bindingSnapshot();

        $response = $this->actAs($user)->getJson('/v1/me/capabilities');

        $response->assertOk()
            ->assertJsonPath('data.wildcard', true)
            ->assertJsonStructure([
                'data' => [
                    'capabilities',
                    'wildcard',
                    'mfa_required',
                    'mfa_verified',
                ],
            ]);

        $rows = $this->capabilityRows($response);
        $expected = app(CapabilityCatalog::class)->codes();
        $codes = array_map(static fn (array $row): string => (string) $row['capability'], $rows);

        sort($codes);

        $sortedExpected = $expected;
        sort($sortedExpected);

        $this->assertSame($sortedExpected, $codes, 'The API must expand the wildcard so the SPA menu is not empty.');
        $this->assertCount(count($expected), $codes);
        $this->assertCount(count($codes), array_unique($codes), 'Each catalogued capability must appear exactly once.');
        $this->assertNotContains(CapabilityWildcard::WILDCARD, $codes, 'The wildcard is not a capability code and must not leak into the expanded set.');

        $validFrom = $wildcardBinding->valid_from->toIso8601ZuluString();
        $validTo = $wildcardBinding->valid_to->toIso8601ZuluString();

        foreach ($rows as $row) {
            $capability = (string) $row['capability'];

            $this->assertSame('LEGAL_ENTITY', $row['anchor_type'], "{$capability} must keep the wildcard binding's anchor.");
            $this->assertSame(1, $row['anchor_id'], "{$capability} must keep the wildcard binding's anchor id.");
            $this->assertSame('P3_RESTRICTED', $row['field_profile'], "{$capability} must keep the wildcard binding's field profile.");
            $this->assertSame('SYSTEM_ADMIN', $row['purpose_code'], "{$capability} must keep the wildcard binding's purpose.");
            $this->assertSame($validFrom, $row['valid_from'], "{$capability} must keep the wildcard binding's validity.");
            $this->assertSame($validTo, $row['valid_to'], "{$capability} must keep the wildcard binding's expiry.");
            $this->assertTrue($row['wildcard'] ?? false, "{$capability} must be marked as wildcard-expanded.");
        }

        $this->assertSame($before, $this->bindingSnapshot(), 'Wildcard expansion must happen only during serialization, never in the database.');
        $this->assertDatabaseCount('authority_bindings', 1);
    }

    public function test_a_wildcard_holder_with_concrete_bindings_still_gets_each_code_once(): void
    {
        $user = User::factory()->create(['tenant_id' => 1, 'auth_version' => 1]);

        $this->grantCapability($user, CapabilityWildcard::WILDCARD, 'LEGAL_ENTITY', 1, 'P3_RESTRICTED', purposeCode: 'SYSTEM_ADMIN');
        $this->grantCapability($user, 'iam.user.read', 'LEGAL_ENTITY', 2, 'P1_INTERNAL', purposeCode: 'READ');
        $this->grantCapability($user, 'iam.user.read', 'LEGAL_ENTITY', 3, 'P2_SENSITIVE', purposeCode: 'READ');

        $response = $this->actAs($user)->getJson('/v1/me/capabilities');

        $response->assertOk()
            ->assertJsonPath('data.wildcard', true);

        $rows = $this->capabilityRows($response);
        $expected = app(CapabilityCatalog::class)->codes();
        $codes = array_map(static fn (array $row): string => (string) $row['capability'], $rows);

        sort($codes);

        $sortedExpected = $expected;
        sort($sortedExpected);

        $this->assertSame($sortedExpected, $codes);
        $this->assertCount(count($expected), $codes);
        $this->assertCount(count($codes), array_unique($codes), 'Concrete bindings must not duplicate a wildcard-expanded capability.');

        $userReadRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['capability'] === 'iam.user.read',
        ));

        $this->assertCount(1, $userReadRows, 'A capability bound both concretely and by wildcard must appear once.');
        $this->assertSame(2, $userReadRows[0]['anchor_id'], 'The concrete binding must keep its own anchor when it supplies the capability.');
        $this->assertSame('P1_INTERNAL', $userReadRows[0]['field_profile'], 'A concrete row must keep its own field profile.');
        $this->assertArrayNotHasKey('wildcard', $userReadRows[0], 'An existing concrete row is not a synthesized wildcard row.');

        $expanded = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['capability'] === 'payroll.run.approve',
        ));

        $this->assertCount(1, $expanded);
        $this->assertSame('LEGAL_ENTITY', $expanded[0]['anchor_type']);
        $this->assertSame(1, $expanded[0]['anchor_id']);
        $this->assertSame('P3_RESTRICTED', $expanded[0]['field_profile']);
        $this->assertSame('SYSTEM_ADMIN', $expanded[0]['purpose_code']);
        $this->assertTrue($expanded[0]['wildcard'] ?? false);

        $this->assertDatabaseCount('authority_bindings', 3);
    }

    public function test_a_non_wildcard_holder_reports_wildcard_false_and_keeps_the_existing_row_shape(): void
    {
        $user = User::factory()->create(['tenant_id' => 1, 'auth_version' => 1]);
        $binding = $this->grantCapability($user, 'iam.user.read', 'LEGAL_ENTITY', 5, 'P1_INTERNAL', purposeCode: 'READ');

        $before = $this->bindingSnapshot();

        $response = $this->actAs($user)->getJson('/v1/me/capabilities');

        $response->assertOk()
            ->assertJsonPath('data.wildcard', false)
            ->assertJsonStructure([
                'data' => [
                    'capabilities',
                    'wildcard',
                    'mfa_required',
                    'mfa_verified',
                ],
            ]);

        $rows = $this->capabilityRows($response);

        $this->assertCount(1, $rows);
        $this->assertSame(
            ['capability', 'anchor_type', 'anchor_id', 'field_profile', 'purpose_code', 'valid_from', 'valid_to'],
            array_keys($rows[0]),
            'The non-wildcard response must keep the existing binding row shape.',
        );
        $this->assertSame('iam.user.read', $rows[0]['capability']);
        $this->assertSame('LEGAL_ENTITY', $rows[0]['anchor_type']);
        $this->assertSame(5, $rows[0]['anchor_id']);
        $this->assertSame('P1_INTERNAL', $rows[0]['field_profile']);
        $this->assertSame('READ', $rows[0]['purpose_code']);
        $this->assertSame($binding->valid_from->toIso8601ZuluString(), $rows[0]['valid_from']);
        $this->assertSame($binding->valid_to->toIso8601ZuluString(), $rows[0]['valid_to']);
        $this->assertArrayNotHasKey('wildcard', $rows[0]);

        $this->assertSame($before, $this->bindingSnapshot());
        $this->assertDatabaseCount('authority_bindings', 1);
    }

    public function test_another_tenants_wildcard_does_not_expand_the_callers_capabilities(): void
    {
        $user = User::factory()->create(['tenant_id' => 1, 'auth_version' => 1]);

        $this->grantCapability(
            $user,
            CapabilityWildcard::WILDCARD,
            'LEGAL_ENTITY',
            99,
            'P0_PUBLIC',
            tenantId: 2,
            purposeCode: 'SYSTEM_ADMIN',
        );
        $this->grantCapability($user, 'people.employee.read', 'LEGAL_ENTITY', 99, 'P0_PUBLIC', tenantId: 2);

        $this->actAs($user, tenantId: 1)
            ->getJson('/v1/me/capabilities')
            ->assertOk()
            ->assertJsonPath('data.wildcard', false)
            ->assertJsonCount(0, 'data.capabilities');

        $this->assertDatabaseCount('authority_bindings', 2);
    }

    /**
     * @param  \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>  $response
     * @return list<array<string, mixed>>
     */
    private function capabilityRows(\Illuminate\Testing\TestResponse $response): array
    {
        $rows = $response->json('data.capabilities');

        $this->assertIsArray($rows);

        return array_values($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bindingSnapshot(): array
    {
        return AuthorityBinding::query()->orderBy('id')->get()->toArray();
    }

    private function actAs(User $user, int $tenantId = 1): self
    {
        $principal = new AuthenticatedPrincipal(
            userId: (int) $user->id,
            tenantId: $tenantId,
            employmentId: null,
            authVersion: (int) $user->auth_version,
            privileged: false,
            mfa: false,
            sessionId: 'test-session',
        );

        $this->app->instance(Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);
        $this->app->make(AuthenticationContext::class)->set($principal);

        return $this;
    }

    private function grantCapability(
        User $user,
        string $capability,
        string $anchorType,
        ?int $anchorId,
        string $fieldProfile,
        int $tenantId = 1,
        string $purposeCode = 'TEST',
    ): AuthorityBinding {
        return AuthorityBinding::factory()
            ->forPrincipal($tenantId, (int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::from($anchorType), $anchorId, FieldProfile::from($fieldProfile))
            ->create([
                'capability_code' => $capability,
                'purpose_code' => $purposeCode,
            ]);
    }
}
