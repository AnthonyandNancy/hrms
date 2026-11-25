<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\People\Models\Document;
use App\Modules\People\Services\DocumentService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Projections\ProjectionResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Thin HTTP layer for K3 employee documents. All authority checks happen in the
 * `capability:` middleware; this controller only validates, delegates and maps
 * the result to the standard envelope.
 */
final class EmployeeDocumentController
{
    public function __construct(
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
        private readonly DocumentService $documents,
        private readonly PrincipalResolver $principals,
    ) {}

    public function index(Request $request, int $employmentId): JsonResponse
    {
        $tenantId = $this->principals->resolve()->tenantId();
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $this->documents->listing(
            tenantId: $tenantId,
            employmentId: $employmentId,
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['page_size'] ?? 20),
        );

        return $this->projections->ok($result, ['employment_id' => $employmentId]);
    }

    public function store(Request $request, int $employmentId): JsonResponse
    {
        $actor = $this->principals->resolve();
        $tenantId = $actor->tenantId();

        $data = $request->validate([
            'category' => ['required', Rule::in(Document::CATEGORIES)],
            'retention_class' => ['required', 'string', 'max:32'],
            'signed_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'legal_hold' => ['nullable', 'boolean'],
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $upload = $request->file('file');
        $contents = (string) file_get_contents((string) $upload->getRealPath());

        $definition = new CommandDefinition(
            name: 'people.document.open',
            capability: 'people.document.read',
            anchor: AnchorType::LEGAL_ENTITY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'people.document.attached',
        );

        $result = $this->commands->run(
            definition: $definition,
            // The idempotency ledger hashes the payload as JSON, so the raw
            // upload cannot be part of it. A content hash plus the metadata is
            // the stable identity of this command.
            payload: [
                'employment_id' => $employmentId,
                'category' => $data['category'],
                'retention_class' => $data['retention_class'],
                'signed_at' => $data['signed_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'legal_hold' => (bool) ($data['legal_hold'] ?? false),
                'content_sha256' => hash('sha256', $contents),
                'size_bytes' => strlen($contents),
            ],
            operation: function () use ($tenantId, $employmentId, $data, $contents, $upload, $actor): array {
                $document = $this->documents->openBytes(
                    tenantId: $tenantId,
                    employmentId: $employmentId,
                    input: $data,
                    contents: $contents,
                    originalName: (string) $upload->getClientOriginalName(),
                    mime: (string) ($upload->getClientMimeType() ?? 'application/octet-stream'),
                    actorUserId: $actor->userId(),
                );

                return [
                    'http_status' => 201,
                    'body' => ['id' => (int) $document->id, 'version' => (int) $document->version],
                    'resource_type' => 'document',
                    'resource_id' => (string) $document->id,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        $response = ApiResponse::created($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    public function signedDownload(Request $request, int $documentId): JsonResponse
    {
        $actor = $this->principals->resolve();
        $document = $this->documents->findForDownload($actor->tenantId(), $documentId);

        if ($document === null) {
            throw new NotFoundOrForbiddenException;
        }

        return ApiResponse::ok([
            'document_id' => (int) $document->id,
            'url' => $this->documents->signedDownloadUrl((int) $actor->tenantId(), $document, $actor->userId()),
            'expires_in_minutes' => 5,
        ]);
    }

    /**
     * Signed endpoint reached only through the minted URL; the signature is the
     * authorization, so this route is not capability-guarded but is registered
     * as a signed route. It still re-checks tenant ownership.
     */
    public function content(Request $request, int $documentId): Response
    {
        $tenantId = (int) $request->query('tenant_id', 0);
        $document = $this->documents->findForDownload($tenantId, $documentId);

        if ($document === null) {
            throw new NotFoundOrForbiddenException;
        }

        return response($this->documents->contents($tenantId, $document), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="document-'.$documentId.'"',
        ]);
    }

    public function destroy(Request $request, int $documentId): JsonResponse
    {
        $actor = $this->principals->resolve();
        $tenantId = $actor->tenantId();
        $document = $this->documents->findForDownload($tenantId, $documentId);

        if ($document === null) {
            throw new NotFoundOrForbiddenException;
        }

        $definition = new CommandDefinition(
            name: 'people.document.delete',
            capability: 'people.employee.manage',
            anchor: AnchorType::LEGAL_ENTITY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'people.document.deleted',
        );

        $result = $this->commands->run(
            definition: $definition,
            payload: ['document_id' => $documentId],
            operation: function () use ($tenantId, $document, $actor): array {
                $this->documents->delete($tenantId, $document, $actor->userId());

                return ['http_status' => 200, 'body' => ['deleted' => true], 'resource_type' => 'document', 'resource_id' => (string) $document->id];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        $response = ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
