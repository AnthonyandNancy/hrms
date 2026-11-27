<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\AttendanceRecordQuery;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scoped attendance detail reads and the attendance CSV export.
 *
 * This controller is read-only by design: raw punches are never edited or
 * deleted, and every record/export query applies the actor's Time scope
 * before pagination or serialisation. The export action is audited.
 */
final class AttendanceRecordController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly AttendanceRecordQuery $records,
        private readonly ProjectionResponder $projections,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        [$filters, $page, $pageSize] = $this->filters($request);

        $result = $this->records->records($actor, $filters, $page, $pageSize);

        return $this->projections->ok(new ProjectionResult(
            items: $result['items'],
            page: $page,
            pageSize: $pageSize,
            total: $result['total'],
            asOf: $this->scope->today()->value(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: '',
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'attendance_days'],
        ));
    }

    /**
     * Raw punch detail. This endpoint intentionally has no update/delete
     * counterpart; corrections go through POST /attendance/corrections.
     */
    public function punches(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        [$filters, $page, $pageSize] = $this->filters($request);

        $result = $this->records->punches($actor, $filters, $page, $pageSize);

        return $this->projections->ok(new ProjectionResult(
            items: $result['items'],
            page: $page,
            pageSize: $pageSize,
            total: $result['total'],
            asOf: $this->scope->today()->value(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: '',
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'punch_records'],
        ));
    }

    public function export(Request $request): Response
    {
        $actor = $this->scope->actor($request);
        [$filters] = $this->filters($request);

        $rows = $this->records->exportRows($actor, $filters);
        $content = $this->csv($rows);
        $filename = 'attendance-'.$filters['from'].'-'.$filters['to'].'.csv';

        $this->audit->record(
            $actor->userId,
            'time.attendance.exported',
            'attendance_export',
            hash('sha256', $this->exportFingerprint($filters)),
            after: [
                'from' => $filters['from'],
                'to' => $filters['to'],
                'employment_id' => $filters['employment_id'] ?? null,
                'row_count' => count($rows),
                'format' => 'CSV',
                'fields' => AttendanceRecordQuery::EXPORT_FIELDS,
            ],
        );

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @return array{0: array{employment_id?: int|null, from: string, to: string}, 1: int, 2: int}
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'employment_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['required', new BusinessDateRule],
            'to' => ['required', new BusinessDateRule],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $from = (string) $data['from'];
        $to = (string) $data['to'];

        if ($to < $from) {
            throw new ValidationException(['to' => ['The end date must not precede the start date.']]);
        }

        return [
            [
                'employment_id' => isset($data['employment_id']) ? (int) $data['employment_id'] : null,
                'from' => $from,
                'to' => $to,
            ],
            max(1, (int) ($data['page'] ?? 1)),
            max(1, min(100, (int) ($data['page_size'] ?? 20))),
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Unable to build the attendance export.');
        }

        // UTF-8 BOM keeps Excel from mis-decoding Chinese employee names.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, AttendanceRecordQuery::EXPORT_FIELDS, ',', '"', '\\', "\n");

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\', "\n");
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * The export's natural key is the authorised filter, not a mutable row.
     *
     * @param  array{employment_id?: int|null, from: string, to: string}  $filters
     */
    private function exportFingerprint(array $filters): string
    {
        return implode('|', [
            (string) ($filters['employment_id'] ?? 'ALL'),
            (string) $filters['from'],
            (string) $filters['to'],
        ]);
    }
}
