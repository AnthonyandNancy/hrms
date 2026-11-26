<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\People\Models\Employment;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Tests\Feature\Reporting\ReportingTestCase;

/**
 * Builds the five real role identities used by the Volume 2 permission matrix.
 *
 * Grants come from the code-owned RoleTemplateCatalog, so the matrix exercises
 * the production capability engine rather than a permissive test double. Each
 * grant is anchored COMPANY by default; a caller can narrow the anchor to prove
 * the department-manager data-scope denials.
 */
abstract class AuthorizationMatrixScenario extends ReportingTestCase
{
    private ?OrgUnit $deptAUnit = null;

    private ?OrgUnit $deptBUnit = null;

    private ?PeopleScenario $peopleScenario = null;

    private ?Employment $subjectEmployment = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Effective bindings are cached by (principal, auth version, capability,
        // business date). Each matrix test creates new users, but the process
        // cache would otherwise serve a prior test's grants.
        \Illuminate\Support\Facades\Cache::flush();

        // ReportingTestCase wires the real gate, but the People harness may
        // have bound a permissive authorizer earlier in the container's life.
        // The matrix must always exercise the real default-deny engine.
        $this->app->forgetInstance(\App\Shared\Authorization\Authorizer::class);
        $this->app->forgetInstance(\App\Modules\IAM\Auth\CapabilityAuthorizer::class);
        $this->app->instance(
            \App\Shared\Authorization\Authorizer::class,
            $this->app->make(\App\Modules\IAM\Auth\CapabilityAuthorizer::class),
        );

        // The People harness aliases `capability` to a permissive middleware
        // and the route table captures that alias at registration time.
        // Re-register the API routes so the production gate is really used.
        $this->app['router']->aliasMiddleware(
            'capability',
            \App\Modules\IAM\Http\Middleware\RequireCapability::class,
        );
        $this->app['router']->setRoutes(new \Illuminate\Routing\RouteCollection);
        \Illuminate\Support\Facades\Route::prefix('v1')
            ->middleware('api')
            ->group(base_path('routes/api.php'));
    }

    public function userWithRole(string $roleCode, string $anchorType = 'COMPANY', ?int $anchorId = null): User
    {
        $template = RoleTemplateCatalog::find($roleCode);
        if ($template === null) {
            throw new \RuntimeException("Unknown role template {$roleCode}");
        }

        $anchor = AnchorType::tryFrom($anchorType);
        if ($anchor === null) {
            throw new \RuntimeException("Unknown anchor type {$anchorType}");
        }

        $user = User::factory()->create(['auth_version' => 1]);

        foreach ($template->grants as $grant) {
            // Each grant keeps the anchor its role template declares (SELF,
            // MANAGED, ORG_UNIT, ...). Only COMPANY grants are re-anchored when
            // the caller explicitly asks for a narrower department anchor.
            $grantAnchor = $anchor === AnchorType::COMPANY ? $grant->anchorType : $anchor;
            $grantAnchorId = $grantAnchor === AnchorType::COMPANY ? null : $anchorId;

            $this->bind(
                $user,
                $grant->capabilityCode,
                $grantAnchor,
                $grantAnchorId,
                $grant->fieldProfile,
                $roleCode,
            );
        }

        return $user;
    }

    /**
     * HR administrator whose grants stop at P2, so a field-ceiling denial can be
     * proven independently of the role name.
     */
    public function hrWithoutP3(): User
    {
        $user = User::factory()->create(['auth_version' => 1]);

        foreach (['people.employee.read', 'people.employee.manage'] as $capability) {
            $this->bind($user, $capability, AnchorType::COMPANY, null, FieldProfile::P2_SENSITIVE, 'HR_ADMIN');
        }

        return $user;
    }

    public function deptA(): int
    {
        return (int) $this->deptAUnit()->id;
    }

    public function deptB(): int
    {
        return (int) $this->deptBUnit()->id;
    }

    public function employeeIn(int $orgUnitId): Employment
    {
        $scenario = $this->peopleScenario();
        $person = $scenario->person();

        return $scenario->employment($person, 'SYNTH-MATRIX-'.$orgUnitId.'-'.bin2hex(random_bytes(3)), [
            'hire_date' => '2026-01-01',
        ]);
    }

    /**
     * Establish the request identity the way the session middleware does, so
     * the capability gate sees the same principal the test asserts against.
     */
    protected function loginAs(User $user, ?int $employmentId = null): void
    {
        $principal = new \App\Modules\IAM\Auth\AuthenticatedPrincipal(
            userId: (int) $user->id,
            employmentId: $employmentId ?? 1,
            authVersion: (int) $user->auth_version,
            privileged: (bool) $user->is_privileged,
            mfa: true,
            sessionId: 'test-session',
        );

        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);
        $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->set($principal);

        // SELF-anchored grants require a live employment for the subject.
        if (! isset($this->peopleScope->employmentByUser[(int) $user->id])) {
            $employment = $this->subjectEmployment ??= $this->employeeIn($this->deptA());
            $this->peopleScope->employmentByUser[(int) $user->id] = [(int) $employment->id];
        }
    }

    private function deptAUnit(): OrgUnit
    {
        return $this->deptAUnit ??= $this->peopleScenario()->orgUnit('SYNTH-DEPT-A', null, '矩阵甲部门');
    }

    private function deptBUnit(): OrgUnit
    {
        return $this->deptBUnit ??= $this->peopleScenario()->orgUnit('SYNTH-DEPT-B', null, '矩阵乙部门');
    }

    private function peopleScenario(): PeopleScenario
    {
        return $this->peopleScenario ??= new PeopleScenario($this->clock);
    }

    /**
     * Add a single explicit grant, used by cases that need one capability at a
     * deliberately narrower field ceiling than the role template provides.
     */
    /**
     * A bare principal with no role-template grants, for cases that need to
     * test one capability at an explicit ceiling.
     */
    protected function userWithoutTemplateGrants(): User
    {
        return User::factory()->create(['auth_version' => 1]);
    }

    protected function bindDirect(
        User $user,
        string $capability,
        AnchorType $anchor,
        ?int $anchorId,
        FieldProfile $profile,
    ): void {
        $this->bind($user, $capability, $anchor, $anchorId, $profile, 'MATRIX_OVERRIDE');
    }

    private function bind(
        User $user,
        string $capability,
        AnchorType $anchor,
        ?int $anchorId,
        FieldProfile $profile,
        string $purposeCode,
    ): void {
        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, (int) $user->auth_version)
            ->anchor($anchor, $anchorId, $profile)
            ->create([
                'capability_code' => $capability,
                'purpose_code' => $purposeCode,
            ]);
    }
}
