<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Platform\Contracts\IdempotencyRunner;
use App\Modules\Talent\Services\PerformanceTemplateService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\TenantBusinessDate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PerformanceTemplateController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly TenantBusinessDate $businessDates,
        private readonly PerformanceTemplateService $templates,
        private readonly IdempotencyRunner $idempotency,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'performance.cycle.read');
        $items = $this->templates->list($principal->tenantId());

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'performance.cycle.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.key' => ['required', 'string', 'max:64'],
            'sections.*.weight' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $key = (string) $request->header('Idempotency-Key', '');

        if ($key === '') {
            throw new ValidationException(['idempotency_key' => ['A valid Idempotency-Key header is required.']]);
        }

        $result = DB::transaction(fn () => $this->idempotency->run(
            tenantId: $principal->tenantId(),
            principalId: $principal->userId(),
            scope: 'POST:/v1/performance/templates',
            key: $key,
            payload: $data,
            operation: fn (): array => [
                'http_status' => 201,
                'body' => $this->templates->publish($principal->tenantId(), $principal->userId(), $data),
                'resource_type' => 'performance_template',
            ],
        ));

        $response = $result->replayed ? ApiResponse::ok($result->body) : ApiResponse::created($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    private function allow(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today($principal->tenantId())->value();

        if (! $this->authorizer->allows($principal, $capability, \App\Shared\Authorization\AnchorRef::company(), $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
