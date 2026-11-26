<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\PermissionResourceCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Full four-way parity for docs/generated/page-actions.json:
 * manifest action -> CapabilityCatalog -> PermissionResourceCatalog page
 * (capability + required API) -> OpenAPI operation (x-capability and
 * x-action-label) -> real backend service class::method.
 */
final class ActionManifestParityTest extends TestCase
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const OWNED_CAPABILITY_GAPS = [
        'people.employee.export' => 'Volume 3 Task 4',
        'people.contract.export' => 'Volume 3 Task 8',
        'org.unit.export' => 'Volume 3 Task 5',
        'org.position.export' => 'Volume 3 Task 6',
        'recruitment.requisition.export' => 'Volume 3 Task 14',
        'payroll.payslip.read' => 'Volume 3 Task 12',
        'time.leave.admin' => 'Volume 3 Task 10',
        'time.overtime.manage' => 'Volume 3 Task 11',
        'time.travel.manage' => 'Volume 3 Task 11',
        'workflow.template.manage' => 'Volume 3 Task 17',
        'notification.manage' => 'Volume 3 Task 16',
        'system.settings.manage' => 'Volume 3 Task 17',
        'notification.read' => 'Volume 3 Task 16',
        'reporting.report.create' => 'Volume 3 Task 16',
    ];

    /**
     * Frozen permission-workbench shape. Writable pages must expose actions for
     * every declared write API; the permission catalogue stays read-only.
     *
     * @var array<string, array{mode: 'writable'|'read-only', owner: string}>
     */
    private const WORKBENCH_PAGES = [
        'system-users' => ['mode' => 'writable', 'owner' => 'Volume 2 Task 6'],
        'system-roles' => ['mode' => 'writable', 'owner' => 'Volume 2 Task 7'],
        'system-menus' => ['mode' => 'writable', 'owner' => 'Volume 2 Task 8'],
        'system-permissions' => ['mode' => 'read-only', 'owner' => 'Volume 2 Task 8'],
        'system-data-scopes' => ['mode' => 'writable', 'owner' => 'Volume 2 Task 8'],
        'system-field-policies' => ['mode' => 'writable', 'owner' => 'Volume 2 Task 8'],
    ];

    /**
     * Actions added by this task always name the backend service they trigger.
     * Services stay optional for the pre-existing manifest actions.
     *
     * @var list<string>
     */
    private const SERVICE_REQUIRED_PAGES = [
        'system-menus',
        'system-data-scopes',
        'system-field-policies',
    ];

    public function test_generated_manifest_matches_capability_page_openapi_and_service_contracts(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(
            (string) file_get_contents($root.'/docs/generated/page-actions.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $pages = new PermissionResourceCatalog;
        $capabilities = new CapabilityCatalog;
        $operations = $this->openApiOperations($root.'/docs/openapi/openapi.yaml');
        $services = $this->backendServices($root.'/backend/app');

        $this->assertSame(1, $manifest['version'] ?? null);
        $this->assertIsArray($manifest['pages'] ?? null);
        $this->assertNotSame([], $manifest['pages'], 'The generated action manifest must not be empty.');

        $checked = 0;
        $seenPerPage = [];

        foreach ($manifest['pages'] as $pageCode => $actions) {
            $page = $pages->find($pageCode);
            $this->assertNotNull($page, "Unknown page {$pageCode} in manifest.");
            $this->assertIsArray($actions);

            foreach ($actions as $action) {
                $key = (string) ($action['key'] ?? '');
                $this->assertNotSame('', $key, "{$pageCode}: action key is required.");
                $capability = (string) ($action['capability'] ?? '');
                $api = (string) ($action['api'] ?? '');

                // (a) capability exists in CapabilityCatalog.
                $this->assertTrue($capabilities->has($capability), "Unknown capability {$capability} on {$pageCode}/{$key}.");

                // (b) page declares the capability and the API.
                $this->assertContains($capability, $page['actions'], "{$pageCode} does not declare {$capability} for {$key}.");
                $this->assertContains($api, $page['required_apis'], "{$pageCode} does not declare {$api} for {$key}.");

                // (c) OpenAPI operation exists, with matching x-capability and a non-empty x-action-label.
                $this->assertArrayHasKey($api, $operations, "{$pageCode}/{$key}: {$api} is absent from OpenAPI.");
                $operation = $operations[$api];
                $this->assertSame(
                    $capability,
                    $operation['x-capability'] ?? null,
                    "{$pageCode}/{$key}: OpenAPI x-capability does not match the action capability.",
                );
                $label = $operation['x-action-label'] ?? null;
                $this->assertIsString($label, "{$pageCode}/{$key}: {$api} has no x-action-label.");
                $this->assertNotSame('', trim($label), "{$pageCode}/{$key}: {$api} has an empty x-action-label.");

                // (d) every declared backend service class::method exists.
                if (in_array($pageCode, self::SERVICE_REQUIRED_PAGES, true)) {
                    $this->assertArrayHasKey('services', $action, "{$pageCode}/{$key}: services are required on the permission workbench.");
                    $this->assertNotSame([], $action['services'], "{$pageCode}/{$key}: at least one backend service is required.");
                }
                if (array_key_exists('services', $action)) {
                    $this->assertIsArray($action['services'], "{$pageCode}/{$key}: services must be an array.");
                    foreach ($action['services'] as $reference) {
                        $this->assertMatchesRegularExpression(
                            '/^[A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*::[A-Za-z_][A-Za-z0-9_]*$/',
                            (string) $reference,
                            "{$pageCode}/{$key}: invalid service reference {$reference}.",
                        );
                        [$class, $method] = explode('::', (string) $reference, 2);
                        $this->assertArrayHasKey($class, $services, "{$pageCode}/{$key}: unknown service class {$class}.");
                        $this->assertContains($method, $services[$class], "{$pageCode}/{$key}: {$class} has no method {$method}.");
                    }
                }

                $seenPerPage[$pageCode][$api] = ($seenPerPage[$pageCode][$api] ?? 0) + 1;
                $checked++;
            }
        }

        foreach ($seenPerPage as $pageCode => $apis) {
            foreach ($apis as $api => $count) {
                $this->assertSame(1, $count, "{$pageCode} declares {$count} actions for {$api}; exactly one per page is required.");
            }
        }

        $this->assertGreaterThan(0, $checked, 'The generated action manifest must contain at least one action.');
    }

    public function test_permission_workbench_pages_match_their_frozen_mode(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(
            (string) file_get_contents($root.'/docs/generated/page-actions.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $pages = new PermissionResourceCatalog;
        $present = [];
        $skipped = [];

        foreach (self::WORKBENCH_PAGES as $pageCode => $spec) {
            $page = $pages->find($pageCode);
            if ($page === null) {
                $skipped[$pageCode] = $spec['owner'];
                $this->addToAssertionCount(1);

                continue;
            }
            $present[$pageCode] = true;
            $actions = $manifest['pages'][$pageCode] ?? [];
            $writeApis = array_values(array_filter(
                $page['required_apis'],
                static fn (string $api): bool => in_array(explode(' ', $api, 2)[0], self::WRITE_METHODS, true),
            ));

            if ($spec['mode'] === 'read-only') {
                $this->assertSame([], $actions, "{$pageCode} is read-only but the manifest declares actions.");
                $this->assertSame([], $writeApis, "{$pageCode} is read-only but declares write APIs.");

                continue;
            }

            if ($writeApis === []) {
                // Owner has not declared a write API yet; report an explicit skip.
                $skipped[$pageCode] = $spec['owner'];
                $this->addToAssertionCount(1);

                continue;
            }

            $this->assertNotSame([], $actions, "{$pageCode} is writable but has no action.");
            $actionApis = array_column($actions, 'api');
            foreach ($writeApis as $api) {
                $this->assertContains($api, $actionApis, "{$pageCode} write API {$api} has no action.");
            }
        }

        // The workbench must be meaningful: at least two writable pages have
        // landed, and every not-yet-landed page has a named owner.
        $this->assertGreaterThanOrEqual(2, count($present), 'No permission workbench page has landed.');
        foreach ($skipped as $pageCode => $owner) {
            $this->assertMatchesRegularExpression('/^Volume 2 Task [1-9][0-9]*$/', $owner, "{$pageCode} skip has no owner.");
            fwrite(STDERR, "\nWORKBENCH SKIP {$pageCode}: {$owner} has not declared its workbench writes yet.\n");
        }
    }

    public function test_owned_capability_gaps_are_real_and_absent_from_openapi(): void
    {
        $root = dirname(__DIR__, 3);
        $gapDocument = json_decode(
            (string) file_get_contents($root.'/docs/audit/2026-11-26-v2-action-gaps.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $capabilities = new CapabilityCatalog;
        $operations = $this->openApiOperations($root.'/docs/openapi/openapi.yaml');
        $capabilityGaps = $gapDocument['capabilityGaps'] ?? [];

        $this->assertIsArray($capabilityGaps);
        $this->assertSame(
            self::OWNED_CAPABILITY_GAPS,
            array_column($capabilityGaps, 'owner', 'capability'),
            'Every page capability gap must keep its explicit owner.',
        );

        foreach ($capabilityGaps as $gap) {
            $capability = (string) $gap['capability'];
            $this->assertTrue($capabilities->has($capability), "Gap capability {$capability} is absent from CapabilityCatalog.");
            $this->assertIsString($gap['reason'] ?? null, "Gap {$capability} has no reason.");
            $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $gap['reason'], "Gap {$capability} reason must be one line.");

            foreach ($operations as $api => $operation) {
                $this->assertNotSame(
                    $capability,
                    $operation['x-capability'] ?? null,
                    "Stale capability gap {$capability}: OpenAPI {$api} already carries it; add the action and remove the gap.",
                );
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function openApiOperations(string $path): array
    {
        $resolved = realpath($path);
        $this->assertNotFalse($resolved, 'OpenAPI spec is missing at docs/openapi/openapi.yaml');
        $spec = Yaml::parseFile($resolved);
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

    /**
     * Minimal PHP class/method index. Short class names must be unique; the
     * map also accepts fully-qualified names so ambiguous services are named
     * explicitly.
     *
     * @return array<string, list<string>>
     */
    private function backendServices(string $root): array
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $entries = [];
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $namespace = preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $ns) === 1 ? $ns[1] : null;
            if (preg_match('/^[ \t]*(?:final\s+|abstract\s+|readonly\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $class) !== 1) {
                continue;
            }
            preg_match_all('/^[ \t]*public\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $methods);
            $entries[] = [
                'short' => $class[1],
                'fqn' => $namespace === null ? $class[1] : $namespace.'\\'.$class[1],
                'methods' => $methods[1],
            ];
        }

        $shortCounts = array_count_values(array_column($entries, 'short'));
        $services = [];
        foreach ($entries as $entry) {
            $services[$entry['fqn']] = $entry['methods'];
            if (($shortCounts[$entry['short']] ?? 0) === 1) {
                $services[$entry['short']] = $entry['methods'];
            }
        }

        return $services;
    }
}
