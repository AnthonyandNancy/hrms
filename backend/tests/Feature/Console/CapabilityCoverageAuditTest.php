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

        $wildcard = in_array('*', $issued, true);
        $this->assertTrue($wildcard, 'At least one admin template must hold the wildcard.');

        foreach ((new CapabilityCatalog)->codes() as $code) {
            if ($code === 'iam.session.manage') {
                continue;
            }

            $this->assertTrue(
                $wildcard || in_array($code, $issued, true),
                "G-COV-1 would fail: no template issues {$code}.",
            );
        }
    }

    public function test_every_route_capability_is_issued(): void
    {
        $issued = [];

        foreach (RoleTemplateCatalog::templates() as $template) {
            $issued = [...$issued, ...$template->capabilityCodes()];
        }

        foreach (app('router')->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'capability:')) {
                    continue;
                }

                $code = trim(explode(',', substr($middleware, strlen('capability:')))[0]);

                if (in_array($code, ['iam.session.manage'], true)) {
                    continue;
                }

                $this->assertTrue(
                    in_array('*', $issued, true) || in_array($code, $issued, true),
                    "G-COV-3 would fail: route {$route->uri()} requires unissued {$code}.",
                );
            }
        }
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
