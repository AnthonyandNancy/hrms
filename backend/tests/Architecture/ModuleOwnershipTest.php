<?php

declare(strict_types=1);

namespace Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class ModuleOwnershipTest extends TestCase
{
    public function test_people_never_imports_organization_models_or_services(): void
    {
        $violations = [];

        foreach ($this->phpFiles($this->basePath('app/Modules/People')) as $file) {
            $contents = (string) file_get_contents($file);
            $path = str_replace($this->basePath().DIRECTORY_SEPARATOR, '', $file);

            foreach (['App\Modules\Organization\Models', 'App\Modules\Organization\Services', 'App\Modules\Organization\Http'] as $prefix) {
                if (str_contains($contents, $prefix)) {
                    $violations[] = "{$path} imports {$prefix}";
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_organization_never_imports_people_models_or_services(): void
    {
        $violations = [];

        foreach ($this->phpFiles($this->basePath('app/Modules/Organization')) as $file) {
            $contents = (string) file_get_contents($file);
            $path = str_replace($this->basePath().DIRECTORY_SEPARATOR, '', $file);

            foreach (['App\Modules\People\Models', 'App\Modules\People\Services', 'App\Modules\People\Http'] as $prefix) {
                if (str_contains($contents, $prefix)) {
                    $violations[] = "{$path} imports {$prefix}";
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_no_controller_runs_sql_or_eloquent_writes(): void
    {
        $violations = [];

        foreach ([...$this->phpFiles($this->basePath('app/Modules/People/Http')), ...$this->phpFiles($this->basePath('app/Modules/Organization/Http'))] as $file) {
            $contents = (string) file_get_contents($file);
            $path = str_replace($this->basePath().DIRECTORY_SEPARATOR, '', $file);

            foreach (['DB::', '->save(', '->update(', '->create('] as $forbidden) {
                if (str_contains($contents, $forbidden)) {
                    $violations[] = "{$path} contains {$forbidden}";
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_effective_dated_tables_have_no_current_snapshot_columns(): void
    {
        $migration = (string) file_get_contents($this->basePath('database/migrations/2026_11_23_000011_create_people_tables.php'));

        $this->assertStringNotContainsString('current_department_id', $migration);
        $this->assertStringNotContainsString('current_position_id', $migration);
        $this->assertStringNotContainsString('$table->foreignId', $migration);
    }

    public function test_business_modules_do_not_use_where_date(): void
    {
        $violations = [];

        foreach ([...$this->phpFiles($this->basePath('app/Modules/People')), ...$this->phpFiles($this->basePath('app/Modules/Organization'))] as $file) {
            if (str_contains((string) file_get_contents($file), 'whereDate(')) {
                $violations[] = str_replace($this->basePath().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertSame([], $violations);
    }

    private function basePath(string $path = ''): string
    {
        return $this->app->basePath($path);
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
