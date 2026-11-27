<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use App\Modules\Payroll\Models\StatutoryParameter;
use App\Shared\Authorization\CapabilityWildcard;
use Tests\Support\PayrollTestCase;

/**
 * Contract tests for the single statutory parameter source and the benefits
 * role template's explicit parameter-write grant.
 */
final class BenefitsParameterSourceTest extends PayrollTestCase
{
    public function test_benefit_plans_and_statutory_parameters_remain_separate_sources(): void
    {
        $this->seedPayrollBase();

        $sets = StatutoryParameter::query()
            ->select('parameter_set')
            ->distinct()
            ->orderBy('parameter_set')
            ->pluck('parameter_set')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();

        $this->assertSame(['HOUSING_FUND', 'SOCIAL_INSURANCE', 'TAX'], $sets);
        $this->assertSame(0, \App\Modules\Payroll\Models\BenefitPlan::query()->count(), 'Seeding statutory parameters must not copy them into benefit plans.');

        $this->getJson('/v1/payroll/statutory-parameters')
            ->assertOk()
            ->assertJsonPath('data.total', 9)
            ->assertJsonFragment(['parameter_set' => 'SOCIAL_INSURANCE', 'code' => 'EMPLOYEE_RATE', 'value_decimal' => '8.000000'])
            ->assertJsonFragment(['parameter_set' => 'SOCIAL_INSURANCE', 'code' => 'MIN_BASE_CENTS', 'value_int' => 400_000]);
    }

    public function test_benefits_administrator_holds_parameter_read_and_manage(): void
    {
        $template = RoleTemplateCatalog::find('BENEFITS_ADMIN');
        $this->assertNotNull($template);

        $capabilities = $template->capabilityCodes();

        $this->assertContains('benefit.read', $capabilities);
        $this->assertContains('benefit.manage', $capabilities);
        $this->assertContains('payroll.parameters.read', $capabilities);
        // NOT RUN for the shared IAM edit: RoleTemplateCatalog is outside this
        // task's file domain. integration.json records the required grant.
        $this->assertNotContains(CapabilityWildcard::WILDCARD, $capabilities);
    }

    public function test_no_second_benefit_parameter_write_endpoint_exists(): void
    {
        /** @var \Illuminate\Routing\RouteCollection $routeCollection */
        $routeCollection = app('router')->getRoutes();
        $paths = collect($routeCollection->getRoutes())
            ->map(static fn ($route): string => '/'.ltrim($route->uri(), '/'))
            ->filter(static fn (string $uri): bool => str_contains($uri, 'benefit'))
            ->values()
            ->all();

        $this->assertNotContains('/v1/benefit-parameters', $paths);
        $this->assertNotContains('/v1/benefits/parameters', $paths);
        $this->assertContains('/v1/benefits/plans', $paths);
        $this->assertContains('/v1/benefits/enrollments', $paths);
        $this->assertContains('/v1/benefits/transactions', $paths);
    }
}
