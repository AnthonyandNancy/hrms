<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Engagement\Services\NoticeGovernanceService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\PrincipalResolver;
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
        private readonly ProjectionResponder $projections,
    ) {}

    public function submit(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate([
            'business_date' => ['nullable', new BusinessDateRule],
        ]);

        return ApiResponse::created(
            $this->notices->submit(
                $this->principals->resolve(),
                $notice,
                (string) ($data['business_date'] ?? ''),
            ),
        );
    }

    public function decision(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['APPROVE', 'REJECT'])],
            'comment' => ['nullable', 'string', 'max:4000'],
            'business_date' => ['nullable', new BusinessDateRule],
        ]);

        return ApiResponse::ok($this->notices->decide(
            $this->principals->resolve(),
            $notice,
            (string) $data['action'],
            (string) ($data['comment'] ?? ''),
            (string) ($data['business_date'] ?? ''),
        ));
    }

    public function publish(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate([
            'business_date' => ['nullable', new BusinessDateRule],
        ]);

        return ApiResponse::ok($this->notices->publish(
            $this->principals->resolve(),
            $notice,
            (string) ($data['business_date'] ?? ''),
        ));
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
        ));
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
            $this->notices->businessDate($principal->tenantId()),
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

        return ApiResponse::created($this->notices->createTemplate($this->principals->resolve(), $data));
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

        return ApiResponse::ok($this->notices->archive(
            $this->principals->resolve(),
            $notice,
            (string) ($data['reason'] ?? ''),
            (string) ($data['business_date'] ?? ''),
        ));
    }

    public function restore(Request $request, int $notice): JsonResponse
    {
        $data = $request->validate([
            'business_date' => ['nullable', new BusinessDateRule],
        ]);

        return ApiResponse::ok($this->notices->restore(
            $this->principals->resolve(),
            $notice,
            (string) ($data['business_date'] ?? ''),
        ));
    }
}
