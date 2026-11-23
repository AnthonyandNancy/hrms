<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

class WorkflowArchitectureTest extends TestCase
{
    public function test_workflow_does_not_import_iam_people_or_organization_models(): void
    {
        $forbidden = [
            'App\\Modules\\IAM\\Models',
            'App\\Modules\\People\\Models',
            'App\\Modules\\Organization\\Models',
        ];

        $offenders = [];

        foreach ($this->phpFiles(base_path('app/Modules/Workflow')) as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($forbidden as $namespace) {
                if (str_contains($contents, $namespace)) {
                    $offenders[] = $this->relativePath($file).' imports '.$namespace;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_workflow_controllers_do_not_query_other_module_tables(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(base_path('app/Modules/Workflow/Http')) as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match('/DB::table\s*\(\s*[\'"](iam_|people_|persons|employments|assignments|org_units)/', $contents) === 1) {
                $offenders[] = $this->relativePath($file);
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_workflow_sources_have_strict_types(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(base_path('app/Modules/Workflow')) as $file) {
            if (! str_contains((string) file_get_contents($file), 'declare(strict_types=1);')) {
                $offenders[] = $this->relativePath($file);
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_decision_and_case_event_models_block_mutation(): void
    {
        $this->assertTrue((new \ReflectionClass(\App\Modules\Workflow\Models\Decision::class))->hasMethod('booted'));
        $this->assertTrue((new \ReflectionClass(\App\Modules\Workflow\Models\CaseEvent::class))->hasMethod('booted'));
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relativePath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
