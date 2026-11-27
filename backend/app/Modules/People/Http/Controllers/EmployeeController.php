<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Http\Requests\CreateEmployeeRequest;
use App\Modules\People\Models\Document;
use App\Modules\People\Models\EmployeeImportBatch;
use App\Modules\People\Models\Employment;
use App\Modules\People\Queries\AsOfEmployeeQuery;
use App\Modules\People\Services\CreateEmployeeService;
use App\Modules\People\Services\EmploymentService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EmployeeController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request, AsOfEmployeeQuery $query): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'org_unit_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'include_archived' => ['nullable', 'boolean'],
        ]);
        $asOf = $this->businessDates->resolve($data['as_of'] ?? null);

        $result = $query->paginate(
            $principal,
            $this->authorizer,
            'people.employee.read',
            $asOf->value(),
            [
                'org_unit_id' => $data['org_unit_id'] ?? null,
                'q' => $data['q'] ?? null,
                'include_archived' => (bool) ($data['include_archived'] ?? false),
            ],
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 20),
        );

        return ApiResponse::ok($result);
    }

    /**
     * Export uses the exact same as-of/org/keyword predicates and row scope as
     * the list. Columns are a fixed, non-sensitive whitelist; there is no
     * client-controlled field expansion and no P3/P2 identity data.
     */
    public function export(Request $request, AsOfEmployeeQuery $query): Response
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'org_unit_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
            'include_archived' => ['nullable', 'boolean'],
            'fields' => ['prohibited'],
        ]);
        $asOf = $this->businessDates->resolve($data['as_of'] ?? null);
        $filters = [
            'org_unit_id' => $data['org_unit_id'] ?? null,
            'q' => $data['q'] ?? null,
            'include_archived' => (bool) ($data['include_archived'] ?? false),
        ];

        $export = $query->exportRows(
            $principal,
            $this->authorizer,
            'people.employee.export',
            $asOf->value(),
            $filters,
            self::EXPORT_ROW_CAP,
        );

        if ((int) $export['total'] > self::EXPORT_ROW_CAP) {
            throw new \App\Shared\Exceptions\ValidationException(
                ['as_of' => ['The export exceeds the maximum row count; narrow the filters.']],
                'The export exceeds the maximum row count; narrow the filters.',
                ['reason' => 'EXPORT_ROW_CAP_EXCEEDED', 'row_cap' => self::EXPORT_ROW_CAP, 'total' => (int) $export['total']],
            );
        }

        $rows = array_map(
            static fn (array $row): array => array_values($row),
            $export['items'],
        );
        $content = $this->csv($rows);
        $filename = 'employees-'.$asOf->value().'.csv';

        $this->audit->record(
            actorUserId: $principal->userId(),
            action: 'people.employee.exported',
            aggregateType: 'employee_export',
            aggregateId: hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR)),
            after: [
                'as_of' => $asOf->value(),
                'org_unit_id' => $filters['org_unit_id'],
                'q' => $filters['q'],
                'include_archived' => $filters['include_archived'],
                'row_count' => count($rows),
                'format' => 'CSV',
                'fields' => self::EXPORT_FIELDS,
            ],
        );

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function show(Request $request, int $employmentId, AsOfEmployeeQuery $query): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['as_of' => ['nullable', new BusinessDateRule]]);
        $asOf = $this->businessDates->resolve($data['as_of'] ?? null);
        $this->assertPermits($principal, 'people.employee.read', $asOf->value());

        $asOfValue = $asOf->value();
        $employment = Employment::query()
            ->whereKey($employmentId)
            ->where('valid_from', '<=', $asOfValue)
            ->where('valid_to', '>', $asOfValue)
            ->first();

        if ($employment === null) {
            throw new NotFoundOrForbiddenException;
        }

        $visible = $this->authorizer
            ->constrain(
                Employment::query()
                    ->whereKey($employmentId)
                    ->where('valid_from', '<=', $asOfValue)
                    ->where('valid_to', '>', $asOfValue),
                $principal,
                'people.employee.read',
                $asOfValue,
            )
            ->exists();

        if (! $visible) {
            throw new NotFoundOrForbiddenException;
        }

        $projection = $query->detail($employment, $asOf->value());
        $contracts = [];
        $documents = Document::query()
            ->where('owner_type', 'EMPLOYMENT')
            ->where('owner_id', $employmentId)
            ->where('category', 'CONTRACT')
            ->where(function ($builder) use ($asOf): void {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>', $asOf->value());
            })
            ->orderByDesc('version')
            ->get();

        foreach ($documents as $document) {
            $contracts[] = [
                'id' => (int) $document->id,
                'version' => (int) $document->version,
                'signed_at' => $document->getAttribute('signed_at') !== null
                    ? \Carbon\CarbonImmutable::parse((string) $document->getAttribute('signed_at'))->toIso8601ZuluString()
                    : null,
                'expires_at' => $document->getAttribute('expires_at') !== null ? (string) $document->getAttribute('expires_at') : null,
                'retention_class' => (string) $document->retention_class,
            ];
        }

        $projection['active_contracts'] = $contracts;

        return ApiResponse::ok($projection);
    }

    public function archive(Request $request, int $employmentId, EmploymentService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $employment = $service->archive(
            $employmentId,
            (string) $data['reason'],
            $principal->userId(),
        );

        return ApiResponse::ok([
            'employment_id' => (int) $employment->id,
            'archived_at' => $employment->archived_at instanceof \Carbon\CarbonInterface ? $employment->archived_at->toIso8601ZuluString() : null,
            'archived_by' => $employment->archived_by !== null ? (int) $employment->archived_by : null,
            'archive_reason' => $employment->archive_reason,
        ]);
    }

    public function restore(Request $request, int $employmentId, EmploymentService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $employment = $service->restore($employmentId, $principal->userId());

        return ApiResponse::ok([
            'employment_id' => (int) $employment->id,
            'archived_at' => null,
        ]);
    }

    public function timeline(Request $request, int $employmentId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'from' => ['nullable', new BusinessDateRule],
            'to' => ['nullable', new BusinessDateRule],
        ]);
        $this->businessDates->resolve($data['from'] ?? null, 'from');
        $this->businessDates->resolve($data['to'] ?? null, 'to');
        $this->assertPermits($principal, 'people.employee.read', $this->businessDates->today()->value());

        $visible = $this->authorizer
            ->constrain(
                Employment::query()->whereKey($employmentId),
                $principal,
                'people.employee.read',
                $this->businessDates->today()->value(),
            )
            ->first();

        if ($visible === null) {
            throw new NotFoundOrForbiddenException;
        }

        $employment = Employment::query()->whereKey($employmentId)->firstOrFail();

        $from = $data['from'] ?? '0001-01-01';
        $to = $data['to'] ?? '9999-12-31';
        $events = [];

        $events[] = $this->event('EMPLOYMENT', (int) $employment->id, 'EMPLOYMENT_CREATED', (string) $employment->valid_from, ['status' => (string) $employment->status]);

        foreach ($employment->newQuery()->getConnection()->table('assignments')->where('employment_id', $employmentId)->orderBy('valid_from')->get() as $assignment) {
            $events[] = $this->event('ASSIGNMENT', (int) $assignment->id, 'ASSIGNMENT_STARTED', (string) $assignment->valid_from, ['org_unit_id' => (int) $assignment->org_unit_id, 'assignment_type' => (string) $assignment->assignment_type]);
        }

        foreach ($employment->newQuery()->getConnection()->table('employment_terms')->where('employment_id', $employmentId)->orderBy('version')->get() as $terms) {
            $events[] = $this->event('TERMS', (int) $terms->id, 'TERMS_VERSION', (string) $terms->effective_from, ['version' => (int) $terms->version]);
        }

        foreach ($employment->newQuery()->getConnection()->table('documents')->where('owner_type', 'EMPLOYMENT')->where('owner_id', $employmentId)->orderBy('id')->get() as $document) {
            $events[] = $this->event('DOCUMENT', (int) $document->id, 'DOCUMENT_ATTACHED', (string) ($document->signed_at ?? $document->created_at), ['category' => (string) $document->category]);
        }

        $events = array_values(array_filter($events, static fn (array $event): bool => $event['effective_date'] >= $from && $event['effective_date'] < $to));
        usort($events, static fn (array $a, array $b): int => [$a['effective_date'], $a['type'], $a['id']] <=> [$b['effective_date'], $b['type'], $b['id']]);

        return ApiResponse::ok(ApiResponse::list($events, 1, max(1, count($events)), count($events)));
    }

    private function assertPermits(Principal $principal, string $capability, string $on): void
    {
        if (! $this->authorizer->permits($principal, $capability, $on)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * Company-level import batches moved from an employment subroute to a list
     * action. The endpoint lists only real persisted batches and never invents
     * validation counts.
     */
    public function imports(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertPermits($principal, 'people.employee.manage', $this->businessDates->today()->value());
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = max(1, min(100, (int) ($data['page_size'] ?? 20)));
        $query = EmployeeImportBatch::query()->orderByDesc('id');
        $total = (int) (clone $query)->count();
        $items = $query
            ->forPage($page, $pageSize)
            ->get()
            ->map(static fn (EmployeeImportBatch $batch): array => [
                'id' => (int) $batch->id,
                'status' => (string) $batch->status,
                'original_name' => (string) $batch->original_name,
                'total_rows' => (int) $batch->total_rows,
                'valid_rows' => (int) $batch->valid_rows,
                'failed_rows' => (int) $batch->failed_rows,
                'errors' => (array) ($batch->errors_json ?? []),
                'validated_at' => $batch->validated_at !== null
                    ? \Carbon\CarbonImmutable::parse((string) $batch->validated_at)->toIso8601ZuluString()
                    : null,
                'committed_at' => $batch->committed_at !== null
                    ? \Carbon\CarbonImmutable::parse((string) $batch->committed_at)->toIso8601ZuluString()
                    : null,
                'created_at' => $batch->created_at?->toIso8601ZuluString(),
            ])
            ->all();

        return ApiResponse::ok(ApiResponse::list($items, $page, $pageSize, $total));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{type: string, id: int, event: string, effective_date: string, metadata: array<string, mixed>}
     */
    private function event(string $type, int $id, string $event, string $date, array $metadata): array
    {
        return ['type' => $type, 'id' => $id, 'event' => $event, 'effective_date' => $date, 'metadata' => $metadata];
    }

    /**
     * Atomic onboarding: person, employment, assignment and first terms version
     * are created in one transaction (see CreateEmployeeService).
     */
    public function onboard(CreateEmployeeRequest $request, CreateEmployeeService $employees): JsonResponse
    {
        $principal = $this->principals->resolve();
        $result = $employees->onboard(
            input: $request->validated(),
            actorUserId: $principal->userId(),
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return ApiResponse::ok($result, $result['replayed'] ? 200 : 201);
    }

    /** @var list<string> */
    private const EXPORT_FIELDS = [
        'employee_no',
        'person_name',
        'status',
        'hire_date',
        'org_unit_name',
        'position_title',
    ];

    private const EXPORT_ROW_CAP = 10000;

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Unable to build the employee export.');
        }

        // UTF-8 BOM keeps Excel from mis-decoding Chinese employee names.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::EXPORT_FIELDS, ',', '"', '\\', "\n");

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\', "\n");
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }
}
