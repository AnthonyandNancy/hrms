<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Engagement\Services\NoticeService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NoticeController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly NoticeService $notices,
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
            'business_date' => ['nullable', new BusinessDateRule],
        ]);

        return ApiResponse::created(
            $this->notices->create($principal, $data, $data['business_date'] ?? null),
        );
    }

    public function acknowledge(Request $request, int $notice): JsonResponse
    {
        $principal = $this->principals->resolve();

        return ApiResponse::ok($this->notices->acknowledge($principal, $notice));
    }

    public function retract(Request $request, int $notice): JsonResponse
    {
        $principal = $this->principals->resolve();

        return ApiResponse::ok($this->notices->retract($principal, $notice));
    }
}
