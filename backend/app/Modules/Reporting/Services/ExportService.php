<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Reporting\Contracts\ExportDispatcher;
use App\Modules\Reporting\Models\ExportJob;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\Contracts\AuthorityBindingRepository;
use App\Shared\Authorization\FieldProfile;
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
        $requestedFields = array_values(array_map('strval', $fields));
        $this->assertFieldsExportable($principal, $definition, $requestedFields, $asOf);
        $preview = $this->reports->exportRows($principal, $code, $asOf, $filters, $requestedFields);

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
        $scopeFingerprint = $this->scopeFingerprint($principal, $asOf);

        $job = DB::transaction(function () use ($principal, $purpose, $code, $asOf, $filters, $token, $now, $watermark, $fieldWhitelist, $preview, $scopeFingerprint): ExportJob {
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
                    [
                        'action' => 'reporting.export.requested',
                        'actor_user_id' => $principal->userId(),
                        'at' => $now->toIso8601ZuluString(),
                        'scope_fingerprint' => $scopeFingerprint,
                    ],
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

        $this->reauthorizeDownload($principal, $job);

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
     * Re-evaluate every authorization boundary at download time. Possession of
     * a token or a signed URL is never enough: the caller, current auth version,
     * current capability bindings, data scope, field policy, file ownership,
     * expiry and download cap are all checked again.
     */
    private function reauthorizeDownload(Principal $principal, ExportJob $job): void
    {
        /** @var array<string, mixed> $filters */
        $filters = (array) ($job->filters_json ?? []);
        $asOf = (string) ($filters['_as_of'] ?? $this->businessDates->today()->value());
        unset($filters['_as_of']);

        $this->assertExportAllowed($principal, [], $asOf);

        if (! $this->authorizer->permits($principal, 'reporting.report.read', $asOf)) {
            throw new AuthorizationException('Forbidden.');
        }

        $definition = $this->reports->definition((string) $job->report_code);
        $fields = array_values(array_map('strval', (array) ($job->field_whitelist_json ?? $job->fields_json)));
        $this->assertFieldsExportable($principal, $definition, $fields, $asOf);

        // The scope fingerprint captures the caller's current auth version,
        // export/report read bindings and anchor ids at creation. Recomputing
        // it now proves that revoking a role, changing an org scope or bumping
        // auth_version invalidates every previously issued link.
        $expectedScope = $this->storedScopeFingerprint($job);

        if ($expectedScope === null) {
            // Older jobs created before the scope fingerprint was recorded
            // cannot prove their creation-time scope. Fail closed and require
            // the caller to request a fresh export.
            throw new NotFoundOrForbiddenException;
        }

        if (! hash_equals($expectedScope, $this->scopeFingerprint($principal, $asOf))) {
            throw new AuthorizationException('Forbidden.');
        }

        $fileId = (int) ($job->file_object_id ?? 0);

        if ($fileId <= 0) {
            throw new NotFoundOrForbiddenException;
        }

        // The job row is already owner-scoped by find(). Verify that its file
        // reference is the generated CSV for this job rather than an object
        // copied from another export.
        $file = app(\App\Shared\Files\FileMetadataReader::class)->metadata($fileId);
        $expectedName = strtolower((string) $job->report_code).'-'.$job->id.'.csv';

        if ($file === null || $file->originalName !== $expectedName || ! str_contains($file->mime, 'csv')) {
            throw new NotFoundOrForbiddenException;
        }
    }

    /**
     * Re-evaluate each requested column against the current field profile.
     * P2/P3 columns require a grant at or above the field profile on the
     * current binding, not just at creation time.
     *
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $fields
     */
    private function assertFieldsExportable(Principal $principal, array $definition, array $fields, string $asOf): void
    {
        if ($fields === []) {
            throw new ValidationException(['fields' => ['At least one export field is required.']], 'The given data was invalid.', ['reason' => 'EXPORT_FIELDS_REQUIRED']);
        }

        $catalog = [];

        foreach ((array) ($definition['fields'] ?? []) as $field) {
            /** @var array<string, mixed> $field */
            $catalog[(string) $field['key']] = $field;
        }

        $policyFields = [];

        foreach ($fields as $key) {
            $field = $catalog[$key] ?? null;

            if ($field === null) {
                throw new ValidationException(['fields' => ["Field {$key} is not exported by this report."]], 'The given data was invalid.', ['reason' => 'EXPORT_FIELD_NOT_ALLOWED']);
            }

            $profile = FieldProfile::from((string) $field['profile']);

            if ($profile->isHidden()) {
                throw new ValidationException(['fields' => ["Field {$key} is restricted and can never be exported."]], 'The given data was invalid.', ['reason' => 'EXPORT_FIELD_HIDDEN']);
            }

            $policyFields[$key] = $profile;
        }

        foreach ($policyFields as $key => $profile) {
            if ($profile !== FieldProfile::P0_PUBLIC && ! $this->profileGranted($principal, $profile, $asOf)) {
                throw new AuthorizationException('Forbidden.');
            }
        }
    }

    private function profileGranted(Principal $principal, FieldProfile $required, string $asOf): bool
    {
        foreach (app(AuthorityBindingRepository::class)->effectiveBindings(
            $principal->userId(),
            'reporting.export',
            $principal->authVersion(),
            $asOf,
        ) as $grant) {
            if ($grant->fieldProfile->rank() >= $required->rank()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stable fingerprint of the authorization state that defines the export
     * data scope. It deliberately contains no row values.
     */
    private function scopeFingerprint(Principal $principal, string $asOf): string
    {
        $bindings = app(AuthorityBindingRepository::class);
        $rows = [];

        foreach (['reporting.report.read', 'reporting.export'] as $capability) {
            foreach ($bindings->effectiveBindings($principal->userId(), $capability, $principal->authVersion(), $asOf) as $grant) {
                $rows[] = [
                    'capability' => $grant->capabilityCode,
                    'anchor_type' => $grant->anchorType->value,
                    'anchor_id' => $grant->anchorId,
                    // The export field profile has its own per-column check;
                    // the scope fingerprint must not change just because a
                    // different (still sufficient) export grant is selected.
                    'field_profile' => $capability === 'reporting.report.read' ? $grant->fieldProfile->value : null,
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));

        return hash('sha256', json_encode([
            'user_id' => $principal->userId(),
            'auth_version' => $principal->authVersion(),
            'as_of' => $asOf,
            'bindings' => $rows,
        ], JSON_THROW_ON_ERROR));
    }

    private function storedScopeFingerprint(ExportJob $job): ?string
    {
        foreach ((array) ($job->audit_trail_json ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $value = $entry['scope_fingerprint'] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
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
