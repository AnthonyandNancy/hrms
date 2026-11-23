<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupRestoreSmokeTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/hrms-backup-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_script_copies_hashes_restores_and_reports_pass(): void
    {
        $source = $this->directory.'/source.sqlite';
        file_put_contents($source, "SQLite format 3\0".random_bytes(128)."\n");

        [$exitCode, $output] = $this->runScript($source);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('PASS', $output);
        $this->assertMatchesRegularExpression('/sha256:\s+[0-9a-f]{64}/i', $output);
        $this->assertStringContainsString('hashes match', $output);
    }

    public function test_script_fails_for_missing_database(): void
    {
        [$exitCode, $output] = $this->runScript($this->directory.'/does-not-exist.sqlite');

        $this->assertNotSame(0, $exitCode, $output);
        $this->assertStringContainsString('FAIL', $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runScript(string $databasePath): array
    {
        $script = base_path('../deploy/scripts/backup_restore_smoke.sh');
        $this->assertFileExists($script);

        $command = sprintf(
            'bash %s %s',
            escapeshellarg($script),
            escapeshellarg($databasePath),
        );

        $output = [];
        $exitCode = 0;
        exec($command.' 2>&1', $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }
}
