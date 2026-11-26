<?php

declare(strict_types=1);

namespace Tests\Contract;

use Tests\TestCase;

final class NoTenantNoLegalEntityTest extends TestCase
{
    public function test_target_code_has_no_tenant_or_legal_entity_identifiers(): void
    {
        $roots = [
            $this->app->basePath('app'),
            $this->app->basePath('routes'),
            $this->app->basePath('../frontend/src'),
        ];

        $violations = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'ts', 'vue'], true)) {
                    continue;
                }
                $text = (string) file_get_contents($file->getPathname());
                // Migrations are excluded by construction; this scan is production code only.
                if (preg_match('/tenant_id|legal_entity_id|LEGAL_ENTITY|X-Legal-Entity-Id|activeLegalEntityId/', $text) === 1) {
                    $violations[] = str_replace($this->app->basePath().'/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $violations, 'Tenant/legal-entity identifiers remain: '.implode(', ', $violations));
    }
}
