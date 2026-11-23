<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Architecture\ModuleBoundaryScanner;
use Illuminate\Console\Command;

/**
 * Fails the build when an application module reaches across a module
 * boundary or accesses another module's tables directly.
 */
final class ArchTestCommand extends Command
{
    protected $signature = 'arch:test
        {--path= : Modules directory to scan (defaults to app/Modules)}
        {--json : Emit the result as JSON}';

    protected $description = 'Enforce module dependency and table-ownership boundaries';

    public function handle(ModuleBoundaryScanner $scanner): int
    {
        $path = $this->option('path');
        $root = is_string($path) && $path !== ''
            ? $path
            : app_path('Modules');

        $report = $scanner->scan($root);
        $json = (bool) $this->option('json');

        if ($json) {
            $this->line((string) json_encode([
                'ok' => $report->isClean(),
                'files_scanned' => $report->filesScanned(),
                'violations' => array_map(static fn ($finding): array => $finding->toArray(), $report->violations()),
                'warnings' => array_map(static fn ($finding): array => $finding->toArray(), $report->warnings()),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $report->isClean() ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf('Scanned %d module PHP files.', $report->filesScanned()));

        foreach ($report->warnings() as $warning) {
            $this->warn(sprintf(
                'WARN %s:%d [%s] %s',
                $warning->file,
                $warning->line,
                $warning->module,
                $warning->message,
            ));
        }

        if ($report->warnings() !== []) {
            $this->line(sprintf('Warnings (non-blocking): %d', count($report->warnings())));
        }

        if ($report->isClean()) {
            $this->info('Module boundaries OK.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error(sprintf('Module boundary violations: %d', count($report->violations())));

        foreach ($report->violations() as $violation) {
            $this->line(sprintf(
                '  %s:%d [%s] %s',
                $violation->file,
                $violation->line,
                $violation->module,
                $violation->reference,
            ));
            $this->line(sprintf('    %s', $violation->message));
        }

        return self::FAILURE;
    }
}
