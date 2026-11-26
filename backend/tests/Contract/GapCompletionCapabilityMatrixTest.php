<?php

declare(strict_types=1);

namespace Tests\Contract;

use Tests\TestCase;

/**
 * Executable denial matrix for the V1.1 capability clusters.
 *
 * `capability:audit` only checks that a manifest ENTRY exists; this test
 * checks that the entry is strong enough to prove default-deny. Every guarded
 * route needs at least four independent denial cases: who you are, what you
 * may do, where the data lives, and whether the field/export profile permits
 * the payload.
 */
final class GapCompletionCapabilityMatrixTest extends TestCase
{
    private const REQUIRED_CASES = ['unauthenticated', 'no_capability', 'wrong_anchor'];

    /**
     * @return array<string, array{capability: string, anchor: string, risk: string, negative_tests: list<string>}>
     */
    private function matrix(): array
    {
        /** @var array<string, array{capability: string, anchor: string, risk: string, negative_tests: list<string>}> $matrix */
        $matrix = require base_path('tests/Authorization/endpoint_matrix.php');

        return $matrix;
    }

    public function test_every_guarded_route_has_at_least_four_denial_cases(): void
    {
        $weak = [];

        foreach ($this->matrix() as $route => $entry) {
            $count = count(array_unique($entry['negative_tests']));

            if ($count < 4) {
                $weak[$route] = $entry['negative_tests'];
            }
        }

        $this->assertSame([], $weak, 'Routes with fewer than four distinct denial cases: '.json_encode($weak, JSON_THROW_ON_ERROR));
    }

    public function test_every_route_covers_the_three_universal_denials(): void
    {
        $missing = [];

        foreach ($this->matrix() as $route => $entry) {
            foreach (self::REQUIRED_CASES as $case) {
                if (! in_array($case, $entry['negative_tests'], true)) {
                    $missing[$route][] = $case;
                }
            }
        }

        $this->assertSame([], $missing, 'Routes missing a universal denial case: '.json_encode($missing, JSON_THROW_ON_ERROR));
    }

    public function test_a_cross_tenant_denial_backs_every_legal_entity_anchor(): void
    {
        $missing = [];

        foreach ($this->matrix() as $route => $entry) {
            if ($entry['anchor'] !== 'COMPANY') {
                continue;
            }

            $hasCrossTenantOrEntity = in_array('cross_legal_entity', $entry['negative_tests'], true)
                || in_array('cross_anchor', $entry['negative_tests'], true);

            if (! $hasCrossTenantOrEntity) {
                $missing[] = $route;
            }
        }

        $this->assertSame([], $missing, 'Legal-entity routes without a cross-entity denial: '.implode(', ', $missing));
    }

    public function test_a_self_anchor_route_never_claims_a_cross_entity_denial(): void
    {
        $invalid = [];

        foreach ($this->matrix() as $route => $entry) {
            if ($entry['anchor'] !== 'SELF') {
                continue;
            }

            // SELF is resolved from the caller's live employment; a cross-entity
            // denial is not reachable without a second anchor. The stronger
            // requirement for these routes is an employment-presence denial.
            if (in_array('cross_legal_entity', $entry['negative_tests'], true)) {
                $invalid[] = $route;
            }
        }

        $this->assertSame([], $invalid, 'SELF routes cannot exercise a cross-entity denial: '.implode(', ', $invalid));
    }

    public function test_high_and_critical_routes_require_mfa_and_csrf_proof(): void
    {
        $missing = [];

        foreach ($this->matrix() as $route => $entry) {
            if (! in_array($entry['risk'], ['high', 'critical'], true)) {
                continue;
            }

            // MFA is required for every elevated read or write; CSRF only
            // applies to state-changing methods, so GET must not claim it.
            $required = in_array('GET', explode(' ', $route), true)
                ? ['mfa_required']
                : ['mfa_required', 'csrf_missing'];

            foreach ($required as $case) {
                if (! in_array($case, $entry['negative_tests'], true)) {
                    $missing[$route][] = $case;
                }
            }
        }

        $this->assertSame([], $missing, 'Elevated-risk routes missing an MFA or CSRF denial: '.json_encode($missing, JSON_THROW_ON_ERROR));
    }

    public function test_every_matrix_capability_exists_in_the_catalog(): void
    {
        $catalog = app(\App\Modules\IAM\Authorization\CapabilityCatalog::class);
        $unknown = [];

        foreach ($this->matrix() as $route => $entry) {
            if (! $catalog->has($entry['capability'])) {
                $unknown[$route] = $entry['capability'];
            }
        }

        $this->assertSame([], $unknown, 'Matrix references unknown capabilities: '.json_encode($unknown, JSON_THROW_ON_ERROR));
    }

    public function test_every_manifest_route_is_actually_registered(): void
    {
        $registered = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'v1/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $registered["{$method} /{$route->uri()}"] = true;
            }
        }

        $stale = array_values(array_filter(
            array_keys($this->matrix()),
            static fn (string $route): bool => ! isset($registered[$route]),
        ));

        $this->assertSame([], $stale, 'Matrix lists routes that are not registered: '.implode(', ', $stale));
    }
}
