<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CapabilityAuditCommandTest extends TestCase
{
    public function test_audit_passes_for_current_public_health_and_meta_routes(): void
    {
        $this->artisan('capability:audit')
            ->expectsOutputToContain('Capability audit OK.')
            ->expectsOutputToContain('v1/health')
            ->expectsOutputToContain('v1/meta')
            ->assertSuccessful();
    }

    public function test_audit_fails_and_lists_synthetic_unguarded_route(): void
    {
        Route::middleware('api')->prefix('v1')->group(function (): void {
            Route::get('/unguarded-fixture', static fn (): array => ['ok' => true]);
        });

        $this->artisan('capability:audit')
            ->expectsOutputToContain('Capability audit failed')
            ->expectsOutputToContain('UNGUARDED GET v1/unguarded-fixture -')
            ->assertFailed();
    }

    public function test_audit_accepts_capability_middleware_metadata(): void
    {
        Route::middleware(['api', 'capability:people.employee.read'])
            ->prefix('v1')
            ->get('/guarded-fixture', static fn (): array => ['ok' => true]);

        $this->artisan('capability:audit')
            ->expectsOutputToContain('GUARDED GET v1/guarded-fixture people.employee.read')
            ->assertSuccessful();
    }

    public function test_audit_rejects_a_route_default_naming_an_unknown_capability(): void
    {
        // A typo in a capability code must fail the audit: with the wildcard
        // present, an unknown code is otherwise reachable only by superusers
        // and silently denies every other role.
        Route::middleware('api')
            ->prefix('v1')
            ->get('/unknown-default-fixture', static fn (): array => ['ok' => true])
            ->defaults('capability', 'organization.unit.read');

        $this->artisan('capability:audit')
            ->expectsOutputToContain('requires unknown capability organization.unit.read')
            ->assertFailed();
    }

    public function test_audit_accepts_capability_route_default(): void
    {
        Route::middleware('api')
            ->prefix('v1')
            ->get('/default-guarded-fixture', static fn (): array => ['ok' => true])
            ->defaults('capability', 'org.unit.read');

        $this->artisan('capability:audit')
            ->expectsOutputToContain('GUARDED GET v1/default-guarded-fixture org.unit.read')
            ->assertSuccessful();
    }

    public function test_missing_negative_test_manifest_entry_warns_without_failing(): void
    {
        Route::middleware(['api', 'capability:payroll.run.read'])
            ->prefix('v1')
            ->get('/manifest-warning-fixture', static fn (): array => ['ok' => true]);

        $this->artisan('capability:audit')
            ->expectsOutputToContain('No negative-test manifest entry')
            ->expectsOutputToContain('Capability audit OK.')
            ->assertSuccessful();
    }
}
