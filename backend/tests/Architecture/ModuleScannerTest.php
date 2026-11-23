<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Shared\Architecture\ModuleBoundaryScanner;
use PHPUnit\Framework\TestCase;

class ModuleScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/hrms-module-scanner-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    public function test_clean_tree_allows_shared_and_other_module_contracts(): void
    {
        $this->writeModuleFile('People/Services/PeopleService.php', <<<'PHP'
        <?php
        namespace App\Modules\People\Services;

        use App\Modules\Payroll\Contracts\PayrollQuery;
        use App\Modules\People\Models\Person;
        use App\Shared\ApiResponse;

        final class PeopleService
        {
            public function run(PayrollQuery $query, Person $person): ApiResponse
            {
                return ApiResponse::ok();
            }
        }
        PHP);

        $report = (new ModuleBoundaryScanner)->scan($this->root);

        $this->assertTrue($report->isClean());
        $this->assertSame([], $report->violations());
        $this->assertSame([], $report->warnings());
        $this->assertSame(1, $report->filesScanned());
    }

    public function test_cross_module_use_and_fully_qualified_reference_are_violations(): void
    {
        $this->writeModuleFile('People/Services/BadService.php', <<<'PHP'
        <?php
        namespace App\Modules\People\Services;

        use App\Modules\Payroll\Models\Payslip;

        final class BadService
        {
            public function run(): void
            {
                new \App\Modules\Time\Services\TimeService();
            }
        }
        PHP);

        $report = (new ModuleBoundaryScanner)->scan($this->root);

        $this->assertFalse($report->isClean());
        $this->assertCount(2, $report->violations());
        $this->assertSame(
            ['App\Modules\Payroll\Models\Payslip', 'App\Modules\Time\Services\TimeService'],
            array_map(static fn ($violation): string => $violation->reference, $report->violations()),
        );
        $this->assertStringContainsString('People', $report->violations()[0]->message);
    }

    public function test_grouped_use_declarations_are_expanded_and_checked(): void
    {
        $this->writeModuleFile('People/Services/GroupedService.php', <<<'PHP'
        <?php
        namespace App\Modules\People\Services;

        use App\Modules\Payroll\{Contracts\PayrollQuery, Models\PayrollRun};

        final class GroupedService
        {
            public function run(PayrollQuery $query, PayrollRun $run): void {}
        }
        PHP);

        $report = (new ModuleBoundaryScanner)->scan($this->root);

        $this->assertCount(1, $report->violations());
        $this->assertSame('App\Modules\Payroll\Models\PayrollRun', $report->violations()[0]->reference);
    }

    public function test_platform_model_import_is_not_allowed_by_default(): void
    {
        $this->writeModuleFile('Payroll/Services/AuditWriter.php', <<<'PHP'
        <?php
        namespace App\Modules\Payroll\Services;

        use App\Modules\Platform\Models\AuditEvent;

        final class AuditWriter
        {
            public function write(AuditEvent $event): void {}
        }
        PHP);

        $report = (new ModuleBoundaryScanner)->scan($this->root);

        $this->assertSame([], ModuleBoundaryScanner::EXPLICIT_ALLOWLIST);
        $this->assertCount(1, $report->violations());
        $this->assertSame('App\Modules\Platform\Models\AuditEvent', $report->violations()[0]->reference);
    }

    public function test_platform_may_not_depend_on_business_module_contracts(): void
    {
        $this->writeModuleFile('Platform/Services/PersonLookup.php', <<<'PHP'
        <?php
        namespace App\Modules\Platform\Services;

        use App\Modules\People\Contracts\PersonQuery;

        final class PersonLookup
        {
            public function run(PersonQuery $query): void {}
        }
        PHP);

        $report = (new ModuleBoundaryScanner)->scan($this->root);

        $this->assertCount(1, $report->violations());
        $this->assertSame('App\Modules\People\Contracts\PersonQuery', $report->violations()[0]->reference);
    }

    public function test_db_table_uses_owned_table_and_flags_cross_module_and_unknown_tables(): void
    {
        $this->writeModuleFile('People/Services/TableReader.php', <<<'PHP'
        <?php
        namespace App\Modules\People\Services;

        use Illuminate\Support\Facades\DB;

        final class TableReader
        {
            public function run(): void
            {
                DB::table('persons')->get();
                DB::table('payroll_runs')->get();
                DB::table('unmapped_future_table')->get();
            }
        }
        PHP);

        $report = (new ModuleBoundaryScanner)->scan($this->root);

        $this->assertCount(1, $report->violations());
        $this->assertSame('payroll_runs', $report->violations()[0]->reference);
        $this->assertCount(1, $report->warnings());
        $this->assertSame('unmapped_future_table', $report->warnings()[0]->reference);
    }

    public function test_schema_usage_in_application_modules_is_a_violation(): void
    {
        $this->writeModuleFile('People/Services/SchemaHack.php', <<<'PHP'
        <?php
        namespace App\Modules\People\Services;

        use Illuminate\Support\Facades\Schema;

        final class SchemaHack
        {
            public function run(): void
            {
                Schema::table('persons', static function (): void {});
            }
        }
        PHP);

        $report = (new ModuleBoundaryScanner)->scan($this->root);

        $this->assertCount(1, $report->violations());
        $this->assertStringContainsString('Schema', $report->violations()[0]->message);
    }

    private function writeModuleFile(string $relativePath, string $contents): void
    {
        $path = $this->root.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, str_replace("\n        ", "\n", $contents)."\n");
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
