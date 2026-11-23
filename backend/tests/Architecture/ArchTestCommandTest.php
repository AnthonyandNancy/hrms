<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

class ArchTestCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/hrms-arch-command-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    public function test_command_passes_for_clean_module_tree(): void
    {
        $path = $this->root.'/People/Services/GoodService.php';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, <<<'PHP'
        <?php
        namespace App\Modules\People\Services;

        use App\Modules\Payroll\Contracts\PayrollQuery;

        final class GoodService
        {
            public function run(PayrollQuery $query): void {}
        }
        PHP);

        $this->artisan('arch:test', ['--path' => $this->root])
            ->expectsOutputToContain('Module boundaries OK')
            ->assertSuccessful();
    }

    public function test_command_fails_and_lists_synthetic_cross_module_violation(): void
    {
        $path = $this->root.'/People/Services/BadService.php';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, <<<'PHP'
        <?php
        namespace App\Modules\People\Services;

        use App\Modules\Payroll\Services\PayrollService;

        final class BadService
        {
            public function run(PayrollService $service): void {}
        }
        PHP);

        $this->artisan('arch:test', ['--path' => $this->root])
            ->expectsOutputToContain('Module boundary violations: 1')
            ->expectsOutputToContain('App\Modules\Payroll\Services\PayrollService')
            ->assertFailed();
    }

    public function test_default_command_passes_for_current_application(): void
    {
        $this->artisan('arch:test')
            ->expectsOutputToContain('Module boundaries OK')
            ->assertSuccessful();
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
