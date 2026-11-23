<?php

declare(strict_types=1);

namespace Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class ArchitectureBoundaryTest extends TestCase
{
    public function test_organization_does_not_reference_people_internal_models(): void
    {
        $violations = [];

        foreach ($this->phpFiles($this->basePath('app/Modules/Organization')) as $file) {
            $contents = (string) file_get_contents($file);

            if (str_contains($contents, 'App\\Modules\\People\\Models')) {
                $violations[] = str_replace($this->basePath().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertSame([], $violations, 'Cross-module internal model references found: '.implode(', ', $violations));
    }

    public function test_organization_does_not_reference_people_models_or_tables_directly(): void
    {
        $violations = [];

        foreach ($this->phpFiles($this->basePath('app/Modules/Organization')) as $file) {
            $path = str_replace($this->basePath().DIRECTORY_SEPARATOR, '', $file);
            $contents = (string) file_get_contents($file);

            if (str_contains($contents, 'App\Modules\People\Models')) {
                $violations[] = "{$path} references People models";
            }

            foreach (['persons', 'employments', 'assignments', 'employment_terms', 'documents'] as $table) {
                if (preg_match("/(?:table|from|into)\s*\(?['\"]".$table."['\"]/", $contents) === 1) {
                    $violations[] = "{$path} queries {$table}";
                }
            }
        }

        $this->assertSame([], $violations, 'Organization bypassed People contracts: '.implode(', ', $violations));
    }

    public function test_controllers_contain_no_sql_or_model_writes(): void
    {
        $violations = [];

        foreach ([...$this->phpFiles($this->basePath('app/Modules/People/Http')), ...$this->phpFiles($this->basePath('app/Modules/Organization/Http'))] as $file) {
            $contents = (string) file_get_contents($file);
            $path = str_replace($this->basePath().DIRECTORY_SEPARATOR, '', $file);

            foreach (['DB::', '->create(', '->save(', '->update('] as $forbidden) {
                if (str_contains($contents, $forbidden)) {
                    $violations[] = "{$path} contains {$forbidden}";
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_effective_dated_migrations_do_not_add_current_department_or_position_columns(): void
    {
        $contents = (string) file_get_contents($this->basePath('database/migrations/2026_11_23_000011_create_people_tables.php'));

        $this->assertStringNotContainsString('current_department_id', $contents);
        $this->assertStringNotContainsString('current_position_id', $contents);
    }

    public function test_business_code_does_not_use_where_date_for_business_dates(): void
    {
        $violations = [];

        foreach ([...$this->phpFiles($this->basePath('app/Modules/People')), ...$this->phpFiles($this->basePath('app/Modules/Organization'))] as $file) {
            $contents = (string) file_get_contents($file);

            if (str_contains($contents, 'whereDate(')) {
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
