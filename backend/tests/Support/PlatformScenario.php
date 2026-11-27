<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Auth\AuthenticatedPrincipal;
use App\Modules\IAM\Authorization\RolePageAssignmentCatalog;
use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Modules\Time\Contracts\TimeActor;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real platform identities for Volume 4 feature tests.
 *
 * Every helper creates an actual IAM User, persists the code-owned role
 * template plus its user_roles/role_pages rows, and expands the template into
 * real AuthorityBinding rows. No test double ever decides a platform
 * capability: the production default-deny CapabilityAuthorizer evaluates the
 * persisted rows exactly as it does for a live session.
 *
 * Extend this class for platform HTTP tests; call actAs() before a request or
 * use loginAs() from AuthenticatesRequests when the session path itself is
 * under test.
 */
abstract class PlatformScenario extends TestCase
{
    use RefreshDatabase;

    public const BUSINESS_DATE = '2026-11-23';

    protected FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hrms.default_timezone', 'Asia/Shanghai');
        config()->set('hrms.pii_hmac_key', 'test-pii-hmac-key');

        $this->clock = new FrozenClock(CarbonImmutable::parse(self::BUSINESS_DATE.'T09:00:00Z', 'UTC'));
        $this->app->instance(Clock::class, $this->clock);

        // Start from a deterministic, empty authorization cache. Bindings are
        // cached by principal/auth version/business date and tests create fresh
        // principals, but a stale entry from another suite must never leak.
        \Illuminate\Support\Facades\Cache::flush();

        // The production capability gate is restored after any permissive
        // People/Time test middleware aliased earlier in the process.
        $this->app['router']->aliasMiddleware(
            'capability',
            \App\Modules\IAM\Http\Middleware\RequireCapability::class,
        );
        $this->app->instance(
            \App\Shared\Authorization\Authorizer::class,
            $this->app->make(\App\Modules\IAM\Auth\CapabilityAuthorizer::class),
        );
    }

    /**
     * Create a User and expand one code-owned role template into real rows.
     *
     * The role table, role_capabilities, role_pages, user_roles and
     * authority_bindings are all written, mirroring `iam:sync-capabilities`
     * plus the production role-assignment controller. Grants keep the anchor
     * and field profile declared by the template; COMPANY templates therefore
     * receive the instance-wide anchor with no id. Templates whose grants need
     * an id-bearing anchor (ORG_UNIT, ORG_SUBTREE, CASE, PAYROLL_RUN) must pass
     * that id in $anchorIds keyed by anchor type, otherwise the grant stays
     * fail-closed rather than being silently widened.
     *
     * @param  array<string, int>  $anchorIds
     */
    public function userWithRole(string $roleCode, array $anchorIds = []): User
    {
        $roleCode = strtoupper(trim($roleCode));
        $template = RoleTemplateCatalog::find($roleCode);

        if ($template === null) {
            throw new \InvalidArgumentException("Unknown code-owned role {$roleCode}.");
        }

        $this->persistRoleTemplate($template);

        /** @var User $user */
        $user = User::factory()->create(['auth_version' => 1]);
        $from = $this->clock->now()->startOfDay();
        $to = $from->addYear();

        UserRole::query()->create([
            'user_id' => (int) $user->id,
            'role_code' => $roleCode,
            'valid_from' => $from,
            'valid_to' => $to,
            'granted_by' => null,
            'reason' => 'Synthetic platform scenario',
            'auth_version' => (int) $user->auth_version,
            'created_at' => $this->clock->now(),
        ]);

        $rolePageCatalog = new RolePageAssignmentCatalog;
        foreach ($rolePageCatalog->pagesForRole($roleCode) as $page) {
            RolePage::query()->firstOrCreate([
                'role_code' => $roleCode,
                'page_code' => (string) $page['page_code'],
            ], ['created_at' => $this->clock->now()]);
        }

        foreach ($template->grants as $grant) {
            $anchorId = $grant->anchorType->requiresAnchorId()
                ? ($anchorIds[$grant->anchorType->value] ?? null)
                : null;

            AuthorityBinding::query()->create([
                'principal_id' => (int) $user->id,
                'capability_code' => $grant->capabilityCode,
                'anchor_type' => $grant->anchorType->value,
                'anchor_id' => $anchorId,
                'anchor_key' => $anchorId ?? 0,
                'field_profile' => $grant->fieldProfile->value,
                'purpose_code' => 'ROLE_'.$roleCode,
                'valid_from' => $from,
                'valid_to' => $to,
                'source_type' => 'ROLE',
                'source_id' => null,
                'auth_version' => (int) $user->auth_version,
                'created_at' => $this->clock->now(),
            ]);
        }

        $this->registerEmployment((int) $user->id);

        return $user;
    }

    /**
     * A line manager identity with the real MANAGED-scope workflow decision
     * grants. The caller seeds the managed employment through
     * managedEmploymentIds().
     */
    public function manager(): User
    {
        return $this->userWithRole('LINE_MANAGER');
    }

    /**
     * Read-only audit identity: AUDITOR carries iam.audit.read but no
     * administrative capability.
     */
    public function auditAdmin(): User
    {
        return $this->userWithRole('AUDITOR');
    }

    /**
     * Settings identity backed by SYSTEM_ADMIN, which carries the wildcard
     * company binding and therefore time.calendar.manage,
     * system.parameter.manage and platform.feature.manage as real grants.
     */
    public function settingsAdmin(): User
    {
        return $this->userWithRole('SYSTEM_ADMIN');
    }

    /**
     * The real actor seam for Time-backed platform endpoints. Use this with
     * TimeHttpTestCase::actAsHttp() so the route guard and controller resolve
     * the same SYSTEM_ADMIN grants persisted above.
     */
    public function settingsAdminActor(): TimeActor
    {
        return $this->timeActorFor($this->settingsAdmin());
    }

    /**
     * Bind the authenticated principal the way the session middleware does so
     * the production route guard and controllers see the same user.
     */
    protected function actAs(User $user, ?int $employmentId = null): User
    {
        $principal = new AuthenticatedPrincipal(
            userId: (int) $user->id,
            employmentId: $employmentId ?? $this->employmentId($user),
            authVersion: (int) $user->auth_version,
            privileged: (bool) $user->is_privileged,
            mfa: true,
            sessionId: 'synthetic-platform-session',
        );

        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);
        $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->set($principal);

        return $user;
    }

    /**
     * Map the user to a synthetic employment in the in-memory People port so
     * SELF/MANAGED grants resolve without creating a person record.
     */
    public function registerEmployment(int $userId): int
    {
        $people = $this->app->make(\App\Shared\Authorization\Contracts\PersonEmploymentDirectory::class);

        if (! $people instanceof \Tests\Fakes\FakePersonEmploymentDirectory) {
            throw new \LogicException('PlatformScenario requires the testing People directory fake.');
        }

        $employmentId = $userId + 1000;
        $people->employmentByUser[$userId] = [$employmentId];

        return $employmentId;
    }

    public function employmentId(User $user): int
    {
        return (int) $user->id + 1000;
    }

    /**
     * Declare the direct reports used by a MANAGED-anchor manager identity.
     *
     * @param  list<int>  $employmentIds
     */
    public function managedEmploymentIds(User $user, array $employmentIds): void
    {
        $people = $this->app->make(\App\Shared\Authorization\Contracts\PersonEmploymentDirectory::class);

        if (! $people instanceof \Tests\Fakes\FakePersonEmploymentDirectory) {
            throw new \LogicException('PlatformScenario requires the testing People directory fake.');
        }

        $people->managedByUser[(int) $user->id] = $employmentIds;
    }

    /**
     * Build the Time actor seam expected by TimeHttpTestCase callers from the
     * same real user and its persisted grants, never from a capability list
     * chosen by the test.
     */
    public function timeActorFor(User $user): TimeActor
    {
        $employmentId = $this->employmentId($user);
        $capabilities = AuthorityBinding::query()
            ->where('principal_id', (int) $user->id)
            ->where('auth_version', (int) $user->auth_version)
            ->whereDate('valid_from', '<=', $this->clock->now()->toDateString())
            ->whereDate('valid_to', '>', $this->clock->now()->toDateString())
            ->orderBy('capability_code')
            ->pluck('capability_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();

        return new TimeActor(
            userId: (int) $user->id,
            employmentId: $employmentId,
            scope: 'COMPANY',
            capabilities: $capabilities,
            managedEmploymentIds: [],
        );
    }

    /**
     * Persist the code-owned template exactly as iam:sync-capabilities does.
     */
    private function persistRoleTemplate(\App\Modules\IAM\Authorization\RoleTemplate $template): void
    {
        Role::query()->updateOrCreate(
            ['code' => $template->code],
            [
                'name' => $template->name,
                'is_template' => true,
                'owner' => null,
                'purpose' => $template->purpose,
                'expires_at' => null,
            ],
        );

        foreach ($template->grants as $grant) {
            RoleCapability::query()->firstOrCreate([
                'role_code' => $template->code,
                'capability_code' => $grant->capabilityCode,
                'anchor_type' => $grant->anchorType->value,
            ], [
                'field_profile' => $grant->fieldProfile->value,
            ]);
        }
    }
}
