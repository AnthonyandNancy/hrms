<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Services;

use App\Modules\Platform\Migration\Contracts\CsvDomainImporter;
use App\Modules\Platform\Migration\Contracts\MigrationTargetPort;
use App\Modules\Platform\Migration\Dto\CsvDocument;
use App\Modules\Platform\Migration\Dto\MappingSet;
use App\Modules\Platform\Migration\Dto\MigrationRow;
use App\Modules\Platform\Migration\Dto\PreparedImport;
use App\Modules\Platform\Migration\Dto\ReferenceData;
use App\Modules\Platform\Migration\Dto\RowIssue;
use App\Modules\Platform\Migration\Enums\IssueCode;
use App\Modules\Platform\Migration\Enums\IssueSeverity;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Enums\MigrationRunMode;
use App\Modules\Platform\Migration\Enums\MigrationRunStatus;
use App\Modules\Platform\Migration\Exceptions\TargetUnavailableException;
use App\Modules\Platform\Migration\Importers\AssignmentCsvImporter;
use App\Modules\Platform\Migration\Importers\LeaveOpeningCsvImporter;
use App\Modules\Platform\Migration\Importers\OrganizationCsvImporter;
use App\Modules\Platform\Migration\Importers\PayrollYtdCsvImporter;
use App\Modules\Platform\Migration\Importers\PeopleCsvImporter;
use App\Modules\Platform\Migration\Importers\TermsCsvImporter;
use App\Modules\Platform\Migration\Models\MigrationIssue;
use App\Modules\Platform\Migration\Models\MigrationRun;
use App\Modules\Platform\Migration\Models\StagingRow;
use App\Modules\Platform\Migration\Support\CsvReader;
use App\Modules\Platform\Migration\Support\MappingLoader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates two-phase domain imports:
 *
 * 1. Parse and stage every source row; validate the whole file.
 * 2. Only if zero ERROR issues, stream normalized rows through the domain's
 *    MigrationTargetPort inside one transaction.
 *
 * Every run is persisted before any target write so a crashed worker leaves an
 * auditable RUNNING row, not an anonymous partial import.
 */
final class MigrationRunner
{
    /** @var array<string, CsvDomainImporter> */
    private array $importers;

    public function __construct(
        private readonly CsvReader $reader,
        private readonly MappingLoader $mappings,
        private readonly MigrationTargetRegistry $targets,
        private readonly ReferenceDataBuilder $references,
        OrganizationCsvImporter $organizations,
        PeopleCsvImporter $people,
        AssignmentCsvImporter $assignments,
        TermsCsvImporter $terms,
        LeaveOpeningCsvImporter $leaveOpenings,
        PayrollYtdCsvImporter $payrollYtd,
    ) {
        $this->importers = [
            MigrationDomain::ORGANIZATIONS->value => $organizations,
            MigrationDomain::PEOPLE->value => $people,
            MigrationDomain::ASSIGNMENTS->value => $assignments,
            MigrationDomain::TERMS->value => $terms,
            MigrationDomain::LEAVE_OPENINGS->value => $leaveOpenings,
            MigrationDomain::PAYROLL_YTD->value => $payrollYtd,
        ];
    }

    /**
     * @param  list<string>  $mappingPaths
     */
    public function validate(
        MigrationDomain $domain,
        string $file,
        array $mappingPaths = [],
    ): MigrationRun {
        return $this->run($domain, $file, $mappingPaths, MigrationRunMode::VALIDATE);
    }

    /**
     * @param  list<string>  $mappingPaths
     */
    public function apply(
        MigrationDomain $domain,
        string $file,
        array $mappingPaths = [],
        MigrationRunMode $mode = MigrationRunMode::APPLY,
        bool $allowShadow = false,
    ): MigrationRun {
        if ($mode === MigrationRunMode::DRY_RUN || $mode === MigrationRunMode::VALIDATE) {
            return $this->run($domain, $file, $mappingPaths, MigrationRunMode::DRY_RUN);
        }

        return $this->run($domain, $file, $mappingPaths, MigrationRunMode::APPLY, $allowShadow);
    }

    /**
     * @param  list<string>  $mappingPaths
     */
    private function run(
        MigrationDomain $domain,
        string $file,
        array $mappingPaths,
        MigrationRunMode $mode,
        bool $allowShadow = false,
    ): MigrationRun {
        $now = CarbonImmutable::now('UTC');
        $document = $this->reader->read($file);
        $mappingResult = $this->mappings->load($mappingPaths);
        $mappingSet = $mappingResult['set'];

        $run = MigrationRun::query()->create([
            'domain' => $domain->value,
            'source_file' => $file,
            'source_file_hash' => $document->fileHash,
            'mapping_hash' => $mappingPaths === [] ? null : $mappingSet->sourceHash,
            'mode' => $mode->value,
            'status' => MigrationRunStatus::RUNNING->value,
            'started_at' => $now,
        ]);

        try {
            $target = null;

            if ($mode === MigrationRunMode::APPLY) {
                $target = $this->targets->target($domain, allowShadow: $allowShadow, preferShadow: $allowShadow);

                if ($target instanceof \App\Modules\Platform\Migration\Contracts\DatabaseMigrationTargetPort
                    || $target instanceof ShadowMigrationTarget) {
                    $this->assertDependenciesApplied($domain);
                }
            }

            $references = $this->references->build($domain, dirname($file), $mappingSet);
            $validation = $this->validateDocument($domain, $document, $references, $mappingSet, $run, $mappingResult['issues']);

            $run->forceFill([
                'rows_read' => count($document->rows),
                'rows_valid' => count($validation->rows),
                'rows_failed' => count($validation->failedSourceRows()),
            ])->save();

            if ($validation->hasErrors() || $validation->rows === []) {
                return $this->finish($run, MigrationRunStatus::VALIDATION_FAILED, [
                    'validation' => 'FAILED',
                    'mode' => $mode->value,
                    'target' => 'NOT_WRITTEN',
                ]);
            }

            if ($mode !== MigrationRunMode::APPLY) {
                return $this->finish($run, MigrationRunStatus::VALIDATED, [
                    'validation' => 'PASSED',
                    'mode' => $mode->value,
                    'target' => 'NOT_WRITTEN',
                ]);
            }

            $target ??= $this->targets->target($domain, allowShadow: $allowShadow, preferShadow: $allowShadow);

            return $this->applyRows($run, $target, $validation);
        } catch (TargetUnavailableException $exception) {
            $this->recordIssue($run, new RowIssue(
                IssueSeverity::ERROR,
                IssueCode::TARGET_UNAVAILABLE,
                $exception->getMessage(),
            ));

            return $this->finish($run, MigrationRunStatus::APPLY_FAILED, [
                'target' => 'UNAVAILABLE',
                'reason' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            $this->recordIssue($run, new RowIssue(
                IssueSeverity::ERROR,
                IssueCode::UNEXPECTED_EXCEPTION,
                $this->safeMessage($exception),
            ));

            return $this->finish($run, MigrationRunStatus::FAILED, [
                'exception' => $exception::class,
                'reason' => $this->safeMessage($exception),
            ]);
        }
    }

    /**
     * @param  list<RowIssue>  $mappingIssues
     */
    private function validateDocument(
        MigrationDomain $domain,
        CsvDocument $document,
        ReferenceData $references,
        MappingSet $mappingSet,
        MigrationRun $run,
        array $mappingIssues,
    ): PreparedImport {
        $importer = $this->importers[$domain->value];
        $validation = $importer->validate($document, $references, $mappingSet);
        $issues = [...$mappingIssues, ...$validation->issues];

        $staging = StagingRow::forDomain($domain);
        $stagedByRow = [];

        foreach ($document->rows as $csvRow) {
            $rowIssues = array_values(array_filter(
                $issues,
                static fn (RowIssue $issue): bool => $issue->sourceRowNumber === $csvRow->sourceRowNumber,
            ));
            $normalized = null;

            foreach ($validation->rows as $validRow) {
                if ($validRow->sourceRowNumber === $csvRow->sourceRowNumber) {
                    $normalized = $validRow->data;
                    break;
                }
            }

            $hasError = false;

            foreach ($rowIssues as $issue) {
                if ($issue->severity === IssueSeverity::ERROR) {
                    $hasError = true;
                }
            }

            $record = $staging->newQuery()->create([
                'run_id' => $run->id,
                'source_row_number' => $csvRow->sourceRowNumber,
                'row_hash' => hash('sha256', json_encode($csvRow->values, JSON_THROW_ON_ERROR)),
                'raw_json' => $csvRow->values,
                'normalized_json' => $normalized,
                'validation_status' => $hasError ? 'FAILED' : 'VALID',
                'created_at' => CarbonImmutable::now('UTC'),
            ]);
            $stagedByRow[$csvRow->sourceRowNumber] = (int) $record->id;
        }

        foreach ($issues as $issue) {
            $stgId = $issue->sourceRowNumber !== null ? ($stagedByRow[$issue->sourceRowNumber] ?? null) : null;
            $this->recordIssue($run, $issue, $stgId);
        }

        $rows = [];

        foreach ($validation->rows as $validRow) {
            $stgId = $stagedByRow[$validRow->sourceRowNumber] ?? null;

            if ($stgId === null) {
                continue;
            }

            $rows[] = new MigrationRow($stgId, $validRow->sourceRowNumber, $validRow->data);
        }

        return new PreparedImport($rows, $issues);
    }

    /**
     * @throws Throwable
     */
    private function applyRows(
        MigrationRun $run,
        MigrationTargetPort $target,
        PreparedImport $validation,
    ): MigrationRun {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        try {
            DB::transaction(function () use ($validation, $target, $run, &$created, &$updated, &$skipped): void {
                foreach ($validation->rows as $row) {
                    $result = $target->apply($row, (int) $run->id);

                    match ($result->status) {
                        'CREATED' => $created++,
                        'UPDATED' => $updated++,
                        'SKIPPED' => $skipped++,
                        default => null,
                    };

                    $stg = StagingRow::forDomain($target->domain())
                        ->newQuery()
                        ->whereKey($row->stgId)
                        ->first();

                    $stg?->forceFill([
                        'target_id' => $result->targetId,
                        'validation_status' => $result->status,
                    ])->save();
                }

                $target->finalize((int) $run->id);
            });
        } catch (TargetUnavailableException $exception) {
            $this->recordIssue($run, new RowIssue(
                IssueSeverity::ERROR,
                IssueCode::TARGET_UNAVAILABLE,
                $exception->getMessage(),
            ));

            return $this->finish($run, MigrationRunStatus::APPLY_FAILED, [
                'target' => 'UNAVAILABLE',
                'reason' => $exception->getMessage(),
                'transaction' => 'ROLLED_BACK',
            ]);
        } catch (Throwable $exception) {
            $this->recordIssue($run, new RowIssue(
                IssueSeverity::ERROR,
                IssueCode::TARGET_WRITE_FAILED,
                'Apply transaction rolled back: '.$this->safeMessage($exception),
            ));

            return $this->finish($run, MigrationRunStatus::APPLY_FAILED, [
                'target' => 'WRITE_FAILED',
                'reason' => $this->safeMessage($exception),
                'transaction' => 'ROLLED_BACK',
            ]);
        }

        $run->forceFill([
            'rows_created' => $created,
            'rows_updated' => $updated,
            'rows_skipped' => $skipped,
        ])->save();

        return $this->finish($run, MigrationRunStatus::APPLIED, [
            'target' => $target instanceof ShadowMigrationTarget ? 'SHADOW' : 'DATABASE',
            'transaction' => 'COMMITTED',
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function finish(MigrationRun $run, MigrationRunStatus $status, array $report): MigrationRun
    {
        $run->forceFill([
            'status' => $status->value,
            'finished_at' => CarbonImmutable::now('UTC'),
            'report_json' => [
                ...$report,
                'domain' => $run->domain->value,
                'rows_read' => $run->rows_read,
                'rows_valid' => $run->rows_valid,
                'rows_created' => $run->rows_created,
                'rows_updated' => $run->rows_updated,
                'rows_skipped' => $run->rows_skipped,
                'rows_failed' => $run->rows_failed,
            ],
        ])->save();

        return $run->refresh();
    }

    private function recordIssue(MigrationRun $run, RowIssue $issue, ?int $stgId = null): void
    {
        MigrationIssue::query()->create([
            'run_id' => $run->id,
            'stg_id' => $issue->stgId ?? $stgId,
            'severity' => $issue->severity->value,
            'code' => $issue->code->value,
            'message' => $issue->message,
            'source_row_number' => $issue->sourceRowNumber,
            'field' => $issue->field,
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function assertDependenciesApplied(MigrationDomain $domain): void
    {
        foreach ($domain->dependsOn() as $dependency) {
            $exists = MigrationRun::query()
                ->where('domain', $dependency->value)
                ->where('status', MigrationRunStatus::APPLIED->value)
                ->exists();

            if (! $exists) {
                throw new \App\Modules\Platform\Migration\Exceptions\MigrationBlockedException(
                    "Domain '{$domain->value}' requires an APPLIED '{$dependency->value}' run first.",
                );
            }
        }
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        $message = preg_replace('/(?:[A-Za-z]:\\\\|[A-Za-z]:\/|\/)(?:[^\s:"\']+[\\\\\/])+[^\s:"\']+/', '[path]', $message) ?? $message;
        $message = preg_replace('/\b(?:password|token|secret)=[^\s&]+/i', '$0', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }
}
