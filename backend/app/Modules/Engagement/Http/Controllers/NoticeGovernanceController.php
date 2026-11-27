<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Engagement\Services\NoticeGovernanceService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Notice governance API: approval, live audience preview, read evidence,
 * versioned templates and archive/restore. Reads use the projection envelope
 * where the response is a bounded list; all writes are idempotent commands.
 */
final class NoticeGovernanceController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly NoticeGovernanceService $notices,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
    ) {}

    public function submit(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate([
            'business_date' => ['nullable', new BusinessDateRule],
        ]);
        $businessDate = (string) ($data['business_date'] ?? '');
        $result = $this->commands->run(
            new CommandDefinition(
                name: 'engagement.notice.submit',
                capability: 'engagement.notice.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'engagement.notice.submitted',
            ),
            ['notice_id' => $notice, 'business_date' => $businessDate],
            function () use ($notice, $businessDate): array {
                $payload = $this->notices->submit(
                    $this->principals->resolve(),
                    $notice,
                    $businessDate,
                );

                return [
                    'http_status' => 201,
                    'body' => $payload,
                    'resource_type' => 'notice',
                    'resource_id' => (string) $notice,
                ];
            },
            $this->idempotencyKey($request),
        );

        // Fresh submit remains 201; an idempotent replay is a 200.
        $response = ApiResponse::ok($result->body, $result->status);
        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    public function decision(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['APPROVE', 'REJECT'])],
            'comment' => ['nullable', 'string', 'max:4000'],
            'business_date' => ['nullable', new BusinessDateRule],
        ]);
        $action = (string) $data['action'];
        $comment = (string) ($data['comment'] ?? '');
        $businessDate = (string) ($data['business_date'] ?? '');
        $result = $this->commands->run(
            new CommandDefinition(
                name: 'engagement.notice.decision',
                capability: 'engagement.notice.publish',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'engagement.notice.decision',
            ),
            ['notice_id' => $notice, 'action' => $action, 'comment' => $comment, 'business_date' => $businessDate],
            function () use ($notice, $action, $comment, $businessDate): array {
                $payload = $this->notices->decide(
                    $this->principals->resolve(),
                    $notice,
                    $action,
                    $comment,
                    $businessDate,
                );

                return [
                    'http_status' => 200,
                    'body' => $payload,
                    'resource_type' => 'notice',
                    'resource_id' => (string) $notice,
                ];
            },
            $this->idempotencyKey($request),
        );

        return $this->commandResponse($result->status, $result->body, $result->replayed);
    }

    public function publish(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate([
            'business_date' => ['nullable', new BusinessDateRule],
        ]);
        $businessDate = (string) ($data['business_date'] ?? '');
        $result = $this->commands->run(
            new CommandDefinition(
                name: 'engagement.notice.publish',
                capability: 'engagement.notice.publish',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'engagement.notice.published',
            ),
            ['notice_id' => $notice, 'business_date' => $businessDate],
            function () use ($notice, $businessDate): array {
                $payload = $this->notices->publish(
                    $this->principals->resolve(),
                    $notice,
                    $businessDate,
                );

                return [
                    'http_status' => 200,
                    'body' => $payload,
                    'resource_type' => 'notice',
                    'resource_id' => (string) $notice,
                ];
            },
            $this->idempotencyKey($request),
        );

        return $this->commandResponse($result->status, $result->body, $result->replayed);
    }

    public function audience(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
        ]);

        return ApiResponse::ok($this->notices->audiencePreview(
            $this->principals->resolve(),
            $notice,
            (string) ($data['as_of'] ?? ''),
        ));
    }

    public function reads(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $principal = $this->principals->resolve();
        $result = $this->notices->reads($principal, $notice, $page, $pageSize);

        return $this->projections->ok($this->notices->projection(
            $result['items'],
            $result['total'],
            $result['as_of'],
            $page,
            $pageSize,
            'notice_acknowledgements',
        ), [
            'read_user_ids' => $result['read_user_ids'],
            'unread_user_ids' => $result['unread_user_ids'],
            'read_count' => $result['read_count'],
            'unread_count' => $result['unread_count'],
        ]);
    }

    /**
     * Remind unread audience recipients. This is a CommandRunner command so a
     * retry with the same Idempotency-Key returns the exact stored response;
     * the service still enforces status and audience invariants on a fresh key.
     */
    public function remind(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate([
            'business_date' => ['nullable', new BusinessDateRule],
        ]);
        $key = $request->header('Idempotency-Key');
        $businessDate = (string) ($data['business_date'] ?? '');
        $result = $this->commands->run(
            new CommandDefinition(
                name: 'engagement.notice.remind',
                capability: 'engagement.notice.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'engagement.notice.reminded',
            ),
            ['notice_id' => $notice, 'business_date' => $businessDate],
            function () use ($notice, $businessDate): array {
                $reminders = $this->notices->remind(
                    $this->principals->resolve(),
                    $notice,
                    $businessDate,
                );

                return [
                    'http_status' => 200,
                    'body' => $reminders,
                    'resource_type' => 'notice',
                    'resource_id' => (string) $notice,
                ];
            },
            is_string($key) ? $key : '',
        );
        $response = ApiResponse::ok($result->body, $result->status);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    public function exportReads(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate([
            'fields' => ['nullable', 'string', 'max:500'],
        ]);
        $fields = isset($data['fields'])
            ? array_values(array_filter(array_map('trim', explode(',', (string) $data['fields']))))
            : [];

        return ApiResponse::ok($this->notices->exportReads(
            $this->principals->resolve(),
            $notice,
            $fields,
        ));
    }

    public function templates(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $items = $this->notices->listTemplates($principal);

        return $this->projections->ok($this->notices->projection(
            $items,
            count($items),
            $this->notices->businessDate(),
            1,
            100,
            'notice_templates',
        ));
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/'],
            'name' => ['required', 'string', 'max:160'],
            'body_template' => ['required', 'string', 'max:20000'],
            'variables' => ['nullable', 'array'],
            'variables.*' => ['string', 'regex:/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/'],
        ]);
        $result = $this->commands->run(
            new CommandDefinition(
                name: 'engagement.notice_template.publish',
                capability: 'engagement.notice.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'engagement.notice_template.published',
            ),
            $data,
            function () use ($data): array {
                $payload = $this->notices->createTemplate($this->principals->resolve(), $data);

                return [
                    'http_status' => 201,
                    'body' => $payload,
                    'resource_type' => 'notice_template',
                    'resource_id' => (string) $payload['id'],
                ];
            },
            $this->idempotencyKey($request),
        );

        return $this->commandResponse($result->status, $result->body, $result->replayed);
    }

    public function archiveIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $principal = $this->principals->resolve();
        $result = $this->notices->archived($principal, $page, $pageSize);

        return $this->projections->ok($this->notices->projection(
            $result['items'],
            $result['total'],
            $result['as_of'],
            $page,
            $pageSize,
            'notices_archive',
        ));
    }

    public function archive(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'business_date' => ['nullable', new BusinessDateRule],
        ]);
        $reason = (string) ($data['reason'] ?? '');
        $businessDate = (string) ($data['business_date'] ?? '');
        $result = $this->commands->run(
            new CommandDefinition(
                name: 'engagement.notice.archive',
                capability: 'engagement.notice.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'engagement.notice.archived',
            ),
            ['notice_id' => $notice, 'reason' => $reason, 'business_date' => $businessDate],
            function () use ($notice, $reason, $businessDate): array {
                $payload = $this->notices->archive(
                    $this->principals->resolve(),
                    $notice,
                    $reason,
                    $businessDate,
                );

                return [
                    'http_status' => 200,
                    'body' => $payload,
                    'resource_type' => 'notice',
                    'resource_id' => (string) $notice,
                ];
            },
            $this->idempotencyKey($request),
        );

        return $this->commandResponse($result->status, $result->body, $result->replayed);
    }

    public function restore(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate([
            'business_date' => ['nullable', new BusinessDateRule],
        ]);
        $businessDate = (string) ($data['business_date'] ?? '');
        $result = $this->commands->run(
            new CommandDefinition(
                name: 'engagement.notice.restore',
                capability: 'engagement.notice.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'engagement.notice.restored',
            ),
            ['notice_id' => $notice, 'business_date' => $businessDate],
            function () use ($notice, $businessDate): array {
                $payload = $this->notices->restore(
                    $this->principals->resolve(),
                    $notice,
                    $businessDate,
                );

                return [
                    'http_status' => 200,
                    'body' => $payload,
                    'resource_type' => 'notice',
                    'resource_id' => (string) $notice,
                ];
            },
            $this->idempotencyKey($request),
        );

        return $this->commandResponse($result->status, $result->body, $result->replayed);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');

        return is_string($key) ? $key : '';
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function commandResponse(int $status, array $body, bool $replayed): JsonResponse
    {
        $response = ApiResponse::ok($body, $status);

        if ($replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
