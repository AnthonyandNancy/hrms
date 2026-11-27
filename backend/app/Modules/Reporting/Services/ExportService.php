<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Reporting\Contracts\ExportDispatcher;
use App\Modules\Reporting\Models\ExportJob;
use App\Shared\Authorization\Authorizer;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\Clock;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

final class ExportService
{
    public const MAX_DOWNLOADS = 5;

    public const EXPIRY_HOURS = 24;

    public const ROW_CAP = 50000;

    /** @var list<string> */
    public const PURPOSES = ['BUSINESS_OPERATION', 'AUDIT', 'COMPLIANCE', 'PAYROLL_RECONCILIATION'];

    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportDefinitionCatalog $catalog,
        private readonly ExportJobRunner $runner,
        private readonly ExportDispatcher $dispatcher,
        private readonly AuditRecorder $audit,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(Principal $principal, array $input): array
    {
        $purpose = strtoupper((string) ($input['purpose'] ?? ''));

        if (! in_array($purpose, self::PURPOSES, true)) {
            throw new ValidationException(['purpose' => ['Purpose is not an allowed export purpose.']], 'The given data was invalid.', ['reason' => 'INVALID_EXPORT_PURPOSE']);
        }

        $code = strtoupper((string) ($input['reportCode'] ?? $input['report_code'] ?? ''));
        $asOf = $this->businessDates->resolve(
            isset($input['asOf']) ? (string) $input['asOf'] : (isset($input['as_of']) ? (string) $input['as_of'] : null),
        )->value();
        $fields = $input['fields'] ?? [];
        $filters = $input['filters'] ?? [];

        if (! is_array($fields) || ! is_array($filters)) {
            throw new ValidationException(['fields' => ['Fields and filters must be objects.']], 'The given data was invalid.', ['reason' => 'INVALID_EXPORT_REQUEST']);
        }

        $definition = $this->reports->definition($code);
        $this->assertExportAllowed($principal, $definition, $asOf);
        $this->projections()->ensure($asOf);
        $preview = $this->reports->exportRows($principal, $code, $asOf, $filters, array_values(array_map('strval', $fields)));

        if (count($preview['rows']) > self::ROW_CAP) {
            throw new ValidationException(
                ['fields' => ['The result exceeds the 50,000 row export cap. Narrow the filters or request an approved split export.']],
                'The given data was invalid.',
                ['reason' => 'EXPORT_ROW_CAP_EXCEEDED'],
            );
        }

        $token = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $watermark = sprintf('%s · %s · %s', $principal->userId(), $now->toIso8601ZuluString(), $purpose);
        $fieldWhitelist = array_values(array_map('strval', $fields));

        $job = DB::transaction(function () use ($principal, $purpose, $code, $asOf, $filters, $token, $now, $watermark, $fieldWhitelist, $preview): ExportJob {
            return ExportJob::query()->create([
                'report_code' => $code,
                'purpose' => $purpose,
                'fields_json' => $fieldWhitelist,
                'field_whitelist_json' => $fieldWhitelist,
                'filters_json' => [...$filters, '_as_of' => $asOf],
                'status' => 'QUEUED',
                'row_count' => 0,
                'source_hash' => $preview['source_hash'],
                'audit_trail_json' => [
                    ['action' => 'reporting.export.requested', 'actor_user_id' => $principal->userId(), 'at' => $now->toIso8601ZuluString()],
                ],
                'file_object_id' => null,
                'expires_at' => $now->addHours(self::EXPIRY_HOURS),
                'download_count' => 0,
                'max_downloads' => self::MAX_DOWNLOADS,
                'requested_by' => $principal->userId(),
                'download_token_hash' => hash('sha256', $token),
                'download_token_expires_at' => $now->addHours(self::EXPIRY_HOURS),
                'watermark' => $watermark,
                'part_no' => 1,
                'total_parts' => 1,
                'parent_job_id' => null,
            ]);
        });

        $this->dispatcher->dispatch((int) $job->id);

        $job->refresh();

        return [
            ...$this->payload($job),
            'downloadToken' => $token,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function job(Principal $principal, int $jobId): array
    {
        $job = $this->find(principal: $principal, jobId: $jobId);

        return $this->payload($job);
    }

    /**
     * @return array{contents: string, filename: string}
     */
    public function download(Principal $principal, int $jobId, string $token, bool $signedUrl = false): array
    {
        $job = $this->find(principal: $principal, jobId: $jobId, includeExpired: true);

        if (! $signedUrl && ! $this->tokenMatches($job, $token)) {
            throw new NotFoundOrForbiddenException;
        }

        $now = $this->clock->now();

        if ($job->status !== 'COMPLETED') {
            throw new ConflictException('The export is not ready for download.', ['reason' => 'EXPORT_NOT_READY']);
        }

        if ($job->expires_at <= $now || $job->download_token_expires_at <= $now) {
            throw new ConflictException('The export has expired.', ['reason' => 'EXPORT_EXPIRED']);
        }

        if ($job->download_count >= $job->max_downloads) {
            throw new ConflictException('The export download limit has been reached.', ['reason' => 'EXPORT_DOWNLOAD_LIMIT_REACHED']);
        }

        $contents = $this->runner->contents((int) $job->file_object_id);
        $job->forceFill(['download_count' => $job->download_count + 1])->save();

        $this->audit->record(
            actorUserId: $principal->userId(),
            action: 'reporting.export.downloaded',
            aggregateType: 'export_job',
            aggregateId: (int) $job->id,
            metadata: [
                'report_code' => (string) $job->report_code,
                'purpose' => (string) $job->purpose,
                'download_count' => (int) $job->download_count,
                'row_count' => (int) $job->row_count,
            ],
        );

        $trail = (array) ($job->audit_trail_json ?? []);
        $trail[] = [
            'action' => 'reporting.export.downloaded',
            'actor_user_id' => $principal->userId(),
            'at' => $this->clock->now()->toIso8601ZuluString(),
        ];
        $job->forceFill(['audit_trail_json' => $trail])->save();

        return [
            'contents' => $contents,
            'filename' => strtolower((string) $job->report_code).'-'.$job->id.'.csv',
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function assertExportAllowed(Principal $principal, array $definition, string $asOf): void
    {
        $allowed = $this->authorizer->permits($principal, 'reporting.export', $asOf);

        if (! $allowed) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    private function projections(): ProjectionBuilder
    {
        return app(ProjectionBuilder::class);
    }

    private function find(Principal $principal, int $jobId, bool $includeExpired = false): ExportJob
    {
        $job = ExportJob::query()
            ->whereKey($jobId)
            ->first();

        if ($job === null || (int) $job->requested_by !== $principal->userId()) {
            throw new NotFoundOrForbiddenException;
        }

        return $job;
    }

    private function tokenMatches(ExportJob $job, string $token): bool
    {
        return $token !== '' && hash_equals((string) $job->download_token_hash, hash('sha256', $token));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ExportJob $job): array
    {
        $catalog = $this->catalog->allActive();
        $name = (string) $job->report_code;
        $fields = (array) ($job->field_whitelist_json ?? $job->fields_json);

        foreach ($catalog as $definition) {
            if (($definition['code'] ?? null) === $job->report_code) {
                $name = (string) $definition['name'];
                break;
            }
        }

        return [
            'id' => (string) $job->id,
            'reportCode' => (string) $job->report_code,
            'reportName' => $name,
            'status' => (string) $job->status,
            'purpose' => (string) $job->purpose,
            'fields' => array_values(array_map('strval', $fields)),
            'fieldWhitelist' => array_values(array_map('strval', $fields)),
            'rowCount' => (int) $job->row_count,
            'source_hash' => (string) ($job->source_hash ?? ''),
            'auditTrail' => (array) ($job->audit_trail_json ?? []),
            'createdAt' => $job->created_at?->toIso8601ZuluString(),
            'expiresAt' => $job->expires_at instanceof \Carbon\CarbonInterface
                ? $job->expires_at->toIso8601ZuluString()
                : null,
            'downloadCount' => (int) $job->download_count,
            'maxDownloads' => (int) $job->max_downloads,
            'watermark' => (string) $job->watermark,
            'fileName' => strtolower((string) $job->report_code).'-'.$job->id.'.csv',
            'downloadUrl' => $this->downloadUrl($job),
        ];
    }

    /**
     * History is owner-scoped and always returns the ADR-0029 envelope. Rows
     * expose hashes and whitelists only; the raw token is never re-readable.
     */
    public function history(Principal $principal, int $page, int $pageSize): ProjectionResult
    {
        $query = ExportJob::query()
            ->where('requested_by', $principal->userId())
            ->orderByDesc('id');

        $total = (int) $query->clone()->count();
        $items = $query->forPage($page, $pageSize)
            ->get()
            ->map(fn (ExportJob $job): array => $this->payload($job))
            ->all();

        return new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $this->businessDates->today()->value(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: null,
        );
    }

    private function downloadUrl(ExportJob $job): string
    {
        return URL::temporarySignedRoute(
            'reporting.exports.download',
            \Carbon\CarbonImmutable::now('UTC')->addHours(self::EXPIRY_HOURS),
            ['jobId' => (int) $job->id],
        );
    }
}
