<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\IAM\Authorization\PageActionCatalog;
use Tests\TestCase;

final class PageActionCatalogParityTest extends TestCase
{
    public function test_generated_php_catalog_matches_the_canonical_json_manifest(): void
    {
        $path = base_path('../docs/generated/page-actions.json');
        $this->assertFileExists($path);
        /** @var array{pages: array<string, list<array<string, mixed>>>} $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $catalog = new PageActionCatalog;

        $this->assertSame(array_keys($manifest['pages']), array_keys($catalog->all()));

        foreach ($manifest['pages'] as $pageCode => $actions) {
            $this->assertSame(
                array_column($actions, 'key'),
                array_column($catalog->forPage($pageCode), 'key'),
                "Generated PHP action list drifted for {$pageCode}.",
            );
        }
    }

    public function test_detail_pages_expose_object_actions_and_shared_capabilities_remain_multi_page(): void
    {
        $catalog = new PageActionCatalog;

        $this->assertSame([
            'payroll.run.detail.approve',
            'payroll.run.detail.assign-member',
            'payroll.run.detail.calculate',
            'payroll.run.detail.cancel',
            'payroll.run.detail.close',
            'payroll.run.detail.correct',
            'payroll.run.detail.lock-inputs',
            'payroll.run.detail.mark-paid',
            'payroll.run.detail.publish',
            'payroll.run.detail.reopen',
            'payroll.run.detail.review',
        ], array_column($catalog->forObject('payroll_run'), 'key'));

        $this->assertSame(
            ['employee-detail', 'hr-employees'],
            $catalog->pagesForCapability('people.employee.manage'),
        );
        $this->assertSame(
            ['hr-contracts', 'hr-contracts-by-id', 'hr-employees'],
            $catalog->pagesForCapability('people.contract.manage'),
        );
    }
}
