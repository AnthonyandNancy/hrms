<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Engagement\Services\NoticeGovernanceService;
use App\Modules\Engagement\Services\NoticeService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NoticeController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly NoticeService $notices,
        private readonly NoticeGovernanceService $governance,
        private readonly CommandRunner $commands,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'filter' => ['nullable', 'string', 'in:all,unread,acknowledged,managed'],
        ]);

        $items = $this->notices->list($principal, ['filter' => $data['filter'] ?? 'all']);

        return ApiResponse::ok(array_values($items));
    }

    public function show(Request $request, int $notice): JsonResponse
    {
        $principal = $this->principals->resolve();

        return ApiResponse::ok($this->notices->show($principal, $notice));
    }

    /**
     * Preview a proposed audience before creating a notice. The audience is
     * resolved through the live directory and never persisted as a guess.
     */
    public function previewAudience(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'string', 'in:ALL,ORG,ROLE,all,org,role'],
            'audienceLabel' => ['nullable', 'string', 'max:120'],
            'orgUnitIds' => ['nullable', 'array'],
            'orgUnitIds.*' => ['integer', 'min:1'],
            'roleCodes' => ['nullable', 'array'],
            'roleCodes.*' => ['string', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/'],
            'business_date' => ['nullable', new BusinessDateRule],
        ]);

        return ApiResponse::ok($this->notices->previewAudience(
            $this->principals->resolve(),
            $data,
            isset($data['business_date']) ? (string) $data['business_date'] : null,
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'audience' => ['required', 'string', 'in:ALL,ORG,ROLE,all,org,role'],
            'audienceLabel' => ['nullable', 'string', 'max:120'],
            'audience_label' => ['nullable', 'string', 'max:120'],
            'orgUnitIds' => ['nullable', 'array'],
            'orgUnitIds.*' => ['integer', 'min:1'],
            'org_unit_ids' => ['nullable', 'array'],
            'org_unit_ids.*' => ['integer', 'min:1'],
            'roleCodes' => ['nullable', 'array'],
            'roleCodes.*' => ['string', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/'],
            'role_codes' => ['nullable', 'array'],
            'role_codes.*' => ['string', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/'],
            'publishAt' => ['nullable', 'date'],
            'publish_at' => ['nullable', 'date'],
            'requiresAck' => ['nullable', 'boolean'],
            'requires_ack' => ['nullable', 'boolean'],
            'approvalRequired' => ['nullable', 'boolean'],
            'approval_required' => ['nullable', 'boolean'],
            'template_code' => ['nullable', 'string', 'max:64'],
            'business_date' => ['nullable', new BusinessDateRule],
        ]);

        $businessDate = (string) ($data['business_date'] ?? '');
        $approvalRequired = (bool) ($data['approval_required'] ?? $data['approvalRequired'] ?? false);
        $result = $this->commands->run(
            new CommandDefinition(
                name: 'engagement.notice.create',
                capability: 'engagement.notice.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'engagement.notice.created',
            ),
            ['notice' => $data, 'approval_required' => $approvalRequired],
            function () use ($principal, $data, $businessDate, $approvalRequired): array {
                $created = $this->notices->create($principal, $data, $businessDate);

                if ($approvalRequired) {
                    $created = $this->governance->submit(
                        $principal,
                        (int) $created['id'],
                        $businessDate,
                    );
                }

                return [
                    'http_status' => 201,
                    'body' => $created,
                    'resource_type' => 'notice',
                    'resource_id' => (string) $created['id'],
                ];
            },
            $this->idempotencyKey($request),
        );

        return $this->commandResponse($result->status, $result->body, $result->replayed);
    }

    public function acknowledge(Request $request, int $notice): JsonResponse
    {
        $result = $this->commands->run(
            new CommandDefinition(
                name: 'engagement.notice.acknowledge',
                capability: 'engagement.notice.read',
                anchor: AnchorType::SELF,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'engagement.notice.acknowledged',
            ),
            ['notice_id' => $notice],
            function () use ($notice): array {
                $payload = $this->notices->acknowledge($this->principals->resolve(), $notice);

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

    public function retract(Request $request, int $notice): JsonResponse
    {
        $result = $this->commands->run(
            new CommandDefinition(
                name: 'engagement.notice.retract',
                capability: 'engagement.notice.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'engagement.notice.retracted',
            ),
            ['notice_id' => $notice],
            function () use ($notice): array {
                $payload = $this->notices->retract($this->principals->resolve(), $notice);

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
