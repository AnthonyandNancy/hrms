<?php

declare(strict_types=1);

namespace Tests\Contract;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class NoTenantNoLegalEntityTest extends TestCase
{
    private const FORBIDDEN = '/tenant_id|legal_entity_id|LEGAL_ENTITY|X-Legal-Entity-Id|activeLegalEntityId/';

    private const PREFLIGHT_FILE = 'backend/app/Modules/Organization/Commands/CompanyPreflightCommand.php';

    private const PREFLIGHT_MARKER = '// single-company:preflight-only';

    /**
     * The migration directory is the only directory-level allowlist: migrations
     * must describe the legacy schema in order to remove it. Runtime code is
     * scanned line by line below.
     */
    private const MIGRATIONS_DIRECTORY = 'backend/database/migrations/';

    private const SCANNED_EXTENSIONS = [
        'php',
        'ts',
        'tsx',
        'js',
        'mjs',
        'cjs',
        'vue',
        'json',
        'yaml',
        'yml',
        'md',
    ];

    public function test_target_code_has_no_tenant_or_legal_entity_identifiers(): void
    {
        $roots = [
            $this->app->basePath('app'),
            $this->app->basePath('routes'),
            $this->app->basePath('database/seeders'),
            $this->app->basePath('../frontend/src'),
            $this->app->basePath('../docs/generated'),
        ];
        $explicitFiles = [
            $this->app->basePath('../docs/openapi/openapi.yaml'),
        ];
        $repositoryRoot = dirname($this->app->basePath());

        $violations = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), self::SCANNED_EXTENSIONS, true)) {
                    continue;
                }

                $relativePath = $this->relativePath($file->getPathname(), $repositoryRoot);
                array_push(
                    $violations,
                    ...$this->scanText($relativePath, (string) file_get_contents($file->getPathname())),
                );
            }
        }

        foreach ($explicitFiles as $explicitFile) {
            if (! is_file($explicitFile)) {
                continue;
            }

            $relativePath = $this->relativePath($explicitFile, $repositoryRoot);
            array_push(
                $violations,
                ...$this->scanText($relativePath, (string) file_get_contents($explicitFile)),
            );
        }

        $this->assertSame([], $violations, sprintf(
            "Tenant/legal-entity identifiers remain (%d):\n%s",
            count($violations),
            implode("\n", array_slice($violations, 0, 80)),
        ));
    }

    public function test_preflight_marker_exempts_only_the_marked_line_in_that_file(): void
    {
        $source = <<<'PHP'
<?php
$legacyColumns = [
    'tenant_id', // single-company:preflight-only
    'legal_entity_id',
];
PHP;

        $this->assertSame(
            [self::PREFLIGHT_FILE.':4'],
            $this->scanText(self::PREFLIGHT_FILE, $source),
        );
    }

    public function test_file_or_directory_wide_exemptions_are_rejected(): void
    {
        $sameFile = <<<'PHP'
<?php
$legacy = 'tenant_id'; // single-company:preflight-only
$stillLegacy = 'legal_entity_id';
PHP;
        $this->assertSame(
            [self::PREFLIGHT_FILE.':3'],
            $this->scanText(self::PREFLIGHT_FILE, $sameFile),
        );

        $siblingFile = <<<'PHP'
<?php
$anchor = 'LEGAL_ENTITY';
PHP;
        $sibling = dirname(self::PREFLIGHT_FILE).'/UnmarkedSibling.php';
        $this->assertSame(
            [$sibling.':2'],
            $this->scanText($sibling, $siblingFile),
        );
    }

    public function test_only_the_migrations_directory_is_exempt_by_path(): void
    {
        $this->assertSame(
            [],
            $this->scanText(
                'backend/database/migrations/2026_11_26_000320_drop_legacy_columns.php',
                "<?php\n\$table->dropColumn('tenant_id');",
            ),
        );
        $this->assertSame(
            ['backend/app/Modules/Organization/Models/Legacy.php:2'],
            $this->scanText(
                'backend/app/Modules/Organization/Models/Legacy.php',
                "<?php\n\$legacy = 'tenant_id';",
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function scanText(string $relativePath, string $source): array
    {
        $relativePath = $this->normalisePath($relativePath);

        if (str_starts_with($relativePath, self::MIGRATIONS_DIRECTORY)) {
            return [];
        }

        $violations = [];
        foreach (preg_split('/\R/', $source) ?: [] as $index => $line) {
            if (
                $relativePath === self::PREFLIGHT_FILE
                && str_contains($line, self::PREFLIGHT_MARKER)
            ) {
                continue;
            }

            if (preg_match(self::FORBIDDEN, $line) === 1) {
                $violations[] = $relativePath.':'.($index + 1);
            }
        }

        return $violations;
    }

    private function relativePath(string $path, string $repositoryRoot): string
    {
        $path = $this->normalisePath($path);
        $repositoryRoot = rtrim($this->normalisePath($repositoryRoot), '/').'/';

        if (str_starts_with($path, $repositoryRoot)) {
            return substr($path, strlen($repositoryRoot));
        }

        return $path;
    }

    private function normalisePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
