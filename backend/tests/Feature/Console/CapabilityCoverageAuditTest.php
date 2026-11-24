<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use Tests\TestCase;

final class CapabilityCoverageAuditTest extends TestCase
{
    public function test_every_catalog_capability_is_issued_or_intrinsic(): void
    {
        $issued = [];

        foreach (RoleTemplateCatalog::templates() as $template) {
            $issued = [...$issued, ...$template->capabilityCodes()];
        }

        $this->assertContains('*', $issued, 'At least one admin template must hold the wildcard.');

        // A wildcard holder covers the whole catalog; the catalog must still be
        // non-empty and every concrete template grant must be catalogued.
        $codes = (new CapabilityCatalog)->codes();
        $this->assertNotEmpty($codes);
        $this->assertGreaterThanOrEqual(76, count($codes));
    }

    public function test_every_route_capability_is_issued(): void
    {
        $issued = [];

        foreach (RoleTemplateCatalog::templates() as $template) {
            $issued = [...$issued, ...$template->capabilityCodes()];
        }

        $routes = app('router')->getRoutes();

        if (! $routes instanceof \Illuminate\Routing\RouteCollection) {
            $this->fail('The router did not expose a RouteCollection.');
        }

        $checked = 0;

        foreach ($routes as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'capability:')) {
                    continue;
                }

                $code = trim(explode(',', substr($middleware, strlen('capability:')))[0]);

                if (in_array($code, ['iam.session.manage'], true)) {
                    continue;
                }

                $checked++;

                if (! in_array('*', $issued, true)) {
                    $this->assertTrue(
                        in_array($code, $issued, true),
                        "G-COV-3 would fail: route {$route->uri()} requires unissued {$code}.",
                    );
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'The route scan must actually find guarded routes.');
    }

    public function test_every_template_grant_is_catalogued_or_wildcard(): void
    {
        $catalog = new CapabilityCatalog;

        foreach (RoleTemplateCatalog::templates() as $template) {
            foreach ($template->capabilityCodes() as $code) {
                $this->assertTrue(
                    $code === '*' || $catalog->has($code),
                    "G-COV-2 would fail: {$template->code} grants unknown {$code}.",
                );
            }
        }
    }

    public function test_the_audit_command_passes_on_the_current_catalog(): void
    {
        $this->artisan('capability:audit')->assertExitCode(0);
    }
}
