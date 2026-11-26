<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Platform\Contracts\PrivateFileStore;
use App\Modules\Reporting\Models\ExportJob;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Worker-side execution of one export job. It re-evaluates the report through
 * the same ReportService field rules, writes a UTF-8 BOM CSV to private storage
 * and records the Platform file-object id.
 */
final class ExportJobRunner
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly PrivateFileStore $files,
        private readonly \App\Modules\Platform\Contracts\FileObjectReader $fileReader,
        private readonly Clock $clock,
    ) {}

    public function run(int $exportJobId): void
    {
        $job = ExportJob::query()
            ->whereKey($exportJobId)
            ->first();

        if ($job === null || $job->status === 'COMPLETED') {
            return;
        }

        $job->forceFill(['status' => 'RUNNING'])->save();

        try {
            /** @var array<string, mixed> $filters */
            $filters = (array) $job->filters_json;
            $asOf = (string) ($filters['_as_of'] ?? $this->clock->now()->toDateString());
            unset($filters['_as_of']);
            $principal = new ExportPrincipal((int) $job->requested_by);
            $result = $this->reports->exportRows(
                $principal,
                (string) $job->report_code,
                $asOf,
                $filters,
                array_values(array_map('strval', (array) $job->fields_json)),
            );
            $contents = $this->csv($result['fields'], $result['rows'], (string) $job->watermark);
            $fileId = $this->files->store(
                contents: $contents,
                originalName: strtolower((string) $job->report_code).'-'.$job->id.'.csv',
                mime: 'text/csv; charset=UTF-8',
                purpose: 'REPORT_EXPORT',
                ownerUserId: (int) $job->requested_by,
            );

            DB::transaction(function () use ($job, $result, $fileId): void {
                $job->forceFill([
                    'status' => 'COMPLETED',
                    'row_count' => count($result['rows']),
                    'file_object_id' => $fileId,
                ])->save();
            });
        } catch (\Throwable $exception) {
            $job->forceFill([
                'status' => 'FAILED',
                'failure_reason' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            throw $exception;
        }
    }

    public function contents(int $fileObjectId): string
    {
        return $this->fileReader->contents($fileObjectId);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(array $fields, array $rows, string $watermark): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [...array_map(static fn (array $field): string => (string) $field['label'], $fields), 'watermark'], ',', '"', '');
        $stamp = $this->clock->now()->toIso8601ZuluString();

        foreach ($rows as $row) {
            $line = [];

            foreach ($fields as $field) {
                $value = $row[(string) $field['key']] ?? null;
                $line[] = $value === null ? '' : (string) $value;
            }

            $line[] = $watermark.' · '.$stamp;
            fputcsv($stream, $line, ',', '"', '');
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return is_string($contents) ? $contents : "\xEF\xBB\xBF";
    }
}
