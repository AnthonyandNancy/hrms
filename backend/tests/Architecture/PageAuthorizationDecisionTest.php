<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

final class PageAuthorizationDecisionTest extends TestCase
{
    public function test_adr_0033_and_0034_declare_the_authorization_invariants(): void
    {
        $pageAdr = $this->app->basePath('../docs/adr/0033-page-authorization-and-page-module-boundaries.md');
        $companyAdr = $this->app->basePath('../docs/adr/0034-single-company-remove-tenant-and-legal-entity.md');

        $this->assertFileExists($pageAdr);
        $this->assertFileExists($companyAdr);

        $pageText = (string) file_get_contents($pageAdr);
        foreach ([
            'role_pages',
            '页面权限只决定进入与菜单',
            'AuthorityBinding',
            '一页一组件',
            'PageActionSpec',
            'contract soft delete',
            'superadmin baseline',
            'market defaults',
        ] as $needle) {
            $this->assertStringContainsString($needle, $pageText, "ADR-0033 must state: {$needle}");
        }

        $companyText = (string) file_get_contents($companyAdr);
        foreach ([
            'single company',
            'COMPANY',
            'tenant_id',
            'legal_entity_id',
            'destructive',
        ] as $needle) {
            $this->assertStringContainsString($needle, $companyText, "ADR-0034 must state: {$needle}");
        }
    }
}
