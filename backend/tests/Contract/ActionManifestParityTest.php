<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\PermissionResourceCatalog;
use Tests\TestCase;

final class ActionManifestParityTest extends TestCase
{
    public function test_generated_manifest_matches_backend_catalog_and_pages(): void
    {
        $root = dirname(__DIR__, 3); // backend/tests/Contract -> repository root
        $manifest = json_decode(
            (string) file_get_contents($root.'/docs/generated/page-actions.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $catalog = new PermissionResourceCatalog;
        $capabilities = new CapabilityCatalog;

        $this->assertSame(1, $manifest['version'] ?? null);
        $this->assertIsArray($manifest['pages'] ?? null);
        $this->assertNotSame([], $manifest['pages'], 'The generated action manifest must not be empty.');

        $checked = 0;
        foreach ($manifest['pages'] as $pageCode => $actions) {
            $page = $catalog->find($pageCode);
            $this->assertNotNull($page, "Unknown page {$pageCode} in manifest.");
            $this->assertIsArray($actions);
            foreach ($actions as $action) {
                $this->assertTrue(
                    $capabilities->has($action['capability']),
                    "Unknown capability {$action['capability']}.",
                );
                $this->assertContains(
                    $action['capability'],
                    $page['actions'],
                    "{$pageCode} does not declare {$action['capability']}.",
                );
                $this->assertContains(
                    $action['api'],
                    $page['required_apis'],
                    "{$pageCode} does not declare {$action['api']}.",
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'The generated action manifest must contain at least one action.');
    }
}
