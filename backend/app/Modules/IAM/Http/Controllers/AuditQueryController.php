<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Contracts\SecurityAuditReader;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Observability\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Security audit read/export surface. Filters run on the server; exported
 * fields are allow-listed and every export is itself audited.
 */
final class AuditQueryController
{
    public function __construct(
        private readonly SecurityAuditReader $auditQuery,
        private readonly PrincipalResolver $principals,
        private readonly AuditRecorder $audit,
        private readonly SensitiveAccessAuditor $sensitiveAuditor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $result = $this->auditQuery->page($filters);

        return ApiResponse::ok(ApiResponse::list(
            $result['items'],
            $result['page'],
            $result['page_size'],
            $result['total'],
        ));
    }

    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fields' => ['required', 'array', 'min:1', 'max:5'],
            'fields.*' => ['string', 'distinct'],
            'actor' => ['sometimes', 'nullable', 'string', 'max:32'],
            'action' => ['sometimes', 'nullable', 'string', 'max:64'],
            'result' => ['sometimes', 'nullable', 'string', 'max:16'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $fields = array_values(array_unique(array_map(
            static fn (mixed $field): string => trim((string) $field),
            (array) $validated['fields'],
        )));
        $filters = $this->filters($request);
        $result = $this->auditQuery->export($fields, $filters);

        $principal = $this->principals->resolve();
        $this->sensitiveAuditor->record(
            $principal->userId(),
            'iam.audit.read',
            'audit_events',
            null,
            $fields,
            ['purpose' => 'AUDIT_EXPORT'],
        );
        $this->audit->record(
            $principal->userId(),
            'iam.audit.exported',
            'audit_events',
            0,
            metadata: ['fields' => $fields, 'filters' => $filters],
        );

        return ApiResponse::ok($result);
    }

    /**
     * @return array{actor: string|null, action: string|null, result: string|null, from: string|null, to: string|null, page: int, page_size: int}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'actor' => ['sometimes', 'nullable', 'string', 'max:32'],
            'action' => ['sometimes', 'nullable', 'string', 'max:64'],
            'result' => ['sometimes', 'nullable', 'string', 'max:16'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return [
            'actor' => $validated['actor'] ?? null,
            'action' => $validated['action'] ?? null,
            'result' => $validated['result'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'page' => (int) ($validated['page'] ?? 1),
            'page_size' => (int) ($validated['page_size'] ?? 20),
        ];
    }
}
