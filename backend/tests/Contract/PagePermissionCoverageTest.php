<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\PermissionResourceCatalog;
use App\Modules\IAM\Authorization\RolePageAssignmentCatalog;
use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use App\Shared\Authorization\CapabilityWildcard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PagePermissionCoverageTest extends TestCase
{
    private const INVENTORY_PATH = __DIR__.'/../../../docs/audit/2026-11-25-v11-page-inventory.md';

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function test_the_catalog_contains_exactly_160_unique_page_resources(): void
    {
        $pages = (new PermissionResourceCatalog)->pages();

        $this->assertCount(160, $pages);

        $paths = array_map(static fn (array $page): string => $page['path'], $pages);
        $codes = array_map(static fn (array $page): string => $page['page_code'], $pages);

        $this->assertSame(count($paths), count(array_unique($paths)), 'Page paths must be unique.');
        $this->assertSame(count($codes), count(array_unique($codes)), 'Page codes must be unique.');

        $layers = array_count_values(array_map(static fn (array $page): string => $page['layer'], $pages));
        $this->assertSame(140, $layers['business'] ?? 0);
        $this->assertSame(14, $layers['platform'] ?? 0);
        $this->assertSame(6, $layers['base'] ?? 0);
    }

    public function test_every_page_has_a_capability_and_every_capability_exists(): void
    {
        $catalog = new CapabilityCatalog;
        $pages = (new PermissionResourceCatalog)->pages();

        foreach ($pages as $page) {
            $capability = $page['required_capability'];

            $this->assertTrue(
                $capability === 'public' || $catalog->has($capability),
                "{$page['path']} requires unknown capability {$capability}.",
            );
        }
    }

    public function test_every_page_has_non_empty_required_api_paths_contract_first(): void
    {
        foreach ((new PermissionResourceCatalog)->pages() as $page) {
            $this->assertNotEmpty($page['required_apis'], "{$page['path']} must declare at least one canonical API.");

            foreach ($page['required_apis'] as $operation) {
                $this->assertMatchesRegularExpression(
                    '/^(GET|POST|PUT|PATCH|DELETE) \/v1\/[A-Za-z0-9_{}:\-\/]+$/',
                    $operation,
                    "{$page['path']} has a malformed required API operation: {$operation}",
                );
            }
        }
    }

    public function test_every_page_has_five_states_and_four_negative_test_classes(): void
    {
        $states = ['loading', 'empty', 'error', 'forbidden', 'partial'];
        $negativeClasses = ['unauthenticated', 'no_capability', 'cross_anchor', 'field_or_export'];

        foreach ((new PermissionResourceCatalog)->pages() as $page) {
            $this->assertSame($states, $page['states'], "{$page['path']} must declare five UI states.");
            $this->assertGreaterThanOrEqual(4, count($page['negative_tests']), "{$page['path']} must declare at least four negative classes.");
            $this->assertSame($negativeClasses, array_slice($page['negative_tests'], 0, 4), "{$page['path']} negative classes drifted.");
            $this->assertNotSame('', (string) $page['component_key']);
            $this->assertStringNotContainsString('import(', (string) $page['component_key']);
            $this->assertStringNotContainsString('.vue', (string) $page['component_key']);
        }
    }

    public function test_all_140_inventory_business_paths_are_present(): void
    {
        $inventory = file_get_contents(self::INVENTORY_PATH);
        $this->assertIsString($inventory);

        preg_match_all('/^\|\s*\d+\s*\|[^|]*\|[^|]*\|\s*`(\/[^`]+)`\s*\|/m', $inventory, $matches);
        $inventoryPaths = array_values(array_unique($matches[1]));

        $this->assertCount(140, $inventoryPaths);

        $catalogPaths = array_column((new PermissionResourceCatalog)->pages(), 'path');

        foreach ($inventoryPaths as $path) {
            $this->assertContains($path, $catalogPaths, "Inventory page {$path} is missing from PermissionResourceCatalog.");
        }
    }

    public function test_every_page_is_assigned_to_at_least_one_role(): void
    {
        $assignments = new RolePageAssignmentCatalog;
        $missing = $assignments->missingPageCodes();

        $this->assertSame([], $missing, 'Pages with no role assignment: '.implode(', ', $missing));

        foreach ((new PermissionResourceCatalog)->pages() as $page) {
            $this->assertNotEmpty(
                $assignments->rolesForPage($page['page_code']),
                "{$page['page_code']} must be assigned to at least one role template.",
            );
        }
    }

    public function test_admin_roles_are_assigned_every_page_and_employee_only_self_pages(): void
    {
        $assignments = new RolePageAssignmentCatalog;
        $pages = (new PermissionResourceCatalog)->pages();
        $allCodes = array_column($pages, 'page_code');
        sort($allCodes);

        foreach (['SUPER_ADMIN', 'SYSTEM_ADMIN'] as $role) {
            $rolePages = array_column($assignments->pagesForRole($role), 'page_code');

            $sorted = $rolePages;
            sort($sorted);

            $this->assertSame($allCodes, $sorted, "{$role} must be assigned all 160 pages.");
        }

        $employeePages = $assignments->pagesForRole('EMPLOYEE');
        $this->assertNotEmpty($employeePages);

        foreach ($employeePages as $page) {
            $this->assertSame(
                'SELF',
                $page['default_anchor'],
                "EMPLOYEE page {$page['page_code']} must use a SELF anchor.",
            );
        }

        $this->assertNotContains('/system/users', array_column($employeePages, 'path'));
    }

    public function test_role_page_assignments_only_reference_catalogued_roles_capabilities_and_pages(): void
    {
        $capabilities = new CapabilityCatalog;
        $pages = new PermissionResourceCatalog;
        $assignments = new RolePageAssignmentCatalog;
        $roleCodes = array_map(static fn ($template): string => $template->code, RoleTemplateCatalog::templates());

        $this->assertSame(14, count($assignments->allAssignments()));

        foreach ($assignments->allAssignments() as $roleCode => $assignment) {
            $this->assertContains($roleCode, $roleCodes, "Unknown role template {$roleCode}.");

            foreach ($assignment['pages'] as $pageCode) {
                $this->assertNotNull($pages->find($pageCode), "{$roleCode} assigns unknown page {$pageCode}.");
            }

            foreach ($assignment['actions'] as $capability) {
                $this->assertTrue(
                    CapabilityWildcard::is($capability) || $capabilities->has($capability),
                    "{$roleCode} assigns unknown action capability {$capability}.",
                );
            }
        }
    }

    public function test_every_page_action_exists_and_is_declared_by_the_catalog(): void
    {
        $catalog = new CapabilityCatalog;
        $operations = $this->openApiOperations();
        $pages = (new PermissionResourceCatalog)->pages();
        $declared = [];

        foreach ($pages as $page) {
            $capability = $page['required_capability'];

            if ($capability !== 'public') {
                $declared[$capability] = true;
            }

            foreach ($page['required_apis'] as $operation) {
                $operationCapability = $operations[$operation]['x-capability'] ?? null;

                if (is_string($operationCapability) && $operationCapability !== '') {
                    $declared[$operationCapability] = true;
                }
            }
        }

        $violations = [];

        foreach ($pages as $page) {
            foreach ($page['actions'] as $action) {
                if (! $catalog->has($action)) {
                    $violations[] = "{$page['page_code']} declares unknown capability {$action}";
                }

                if (! isset($declared[$action])) {
                    $violations[] = "{$page['page_code']} declares action {$action} but no page or required API relationship declares that capability";
                }
            }
        }

        $this->assertSame([], $violations, implode('
', $violations));
    }

    public function test_every_required_api_operation_exists_in_openapi(): void
    {
        $operations = $this->openApiOperations();
        $violations = [];

        foreach ((new PermissionResourceCatalog)->pages() as $page) {
            foreach ($page['required_apis'] as $operation) {
                if (! array_key_exists($operation, $operations)) {
                    $violations[] = "{$page['page_code']} requires missing OpenAPI operation {$operation}";
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_every_page_write_api_capability_is_declared_in_actions(): void
    {
        $operations = $this->openApiOperations();
        $violations = [];

        foreach ((new PermissionResourceCatalog)->pages() as $page) {
            if ($page['required_capability'] === 'public') {
                continue;
            }

            foreach ($page['required_apis'] as $operation) {
                [$method] = explode(' ', $operation, 2);

                if (! in_array($method, self::WRITE_METHODS, true)) {
                    continue;
                }

                $openApiOperation = $operations[$operation] ?? null;
                $capability = is_array($openApiOperation) ? ($openApiOperation['x-capability'] ?? null) : null;

                if (! is_string($capability) || $capability === '') {
                    $violations[] = "{$page['page_code']} write API {$operation} has no OpenAPI x-capability";

                    continue;
                }

                if (! in_array($capability, $page['actions'], true)) {
                    $violations[] = "{$page['page_code']} write API {$operation} uses {$capability}, which is absent from actions";
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function openApiOperations(): array
    {
        $path = realpath(__DIR__.'/../../../docs/openapi/openapi.yaml');
        $this->assertNotFalse($path, 'OpenAPI spec is missing at docs/openapi/openapi.yaml');

        $spec = Yaml::parseFile($path);
        $operations = [];

        foreach ($spec['paths'] ?? [] as $apiPath => $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            foreach ($pathItem as $method => $operation) {
                if (! in_array(strtoupper((string) $method), ['GET', ...self::WRITE_METHODS], true) || ! is_array($operation)) {
                    continue;
                }

                $operations[strtoupper((string) $method).' '.$apiPath] = $operation;
            }
        }

        return $operations;
    }

    public function test_permission_catalog_is_deterministic_and_has_17_business_menu_groups(): void
    {
        $first = (new PermissionResourceCatalog)->pages();
        $second = (new PermissionResourceCatalog)->pages();

        $this->assertSame($first, $second);

        $businessGroups = [];

        foreach ($first as $page) {
            if ($page['layer'] === 'business') {
                $businessGroups[$page['menu_group']] = true;
            }
        }

        $this->assertCount(17, $businessGroups);
    }
}
