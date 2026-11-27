<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Services\ReportSubscriptionService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Projections\ProjectionResponder;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Subscription writes are CommandRunner commands so retries replay exactly;
 * list reads use the ADR-0029 projection envelope.
 */
final class ReportSubscriptionController
{
    public function __construct(
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
        private readonly ReportSubscriptionService $subscriptions,
        private readonly PrincipalResolver $principals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->projections->ok($this->subscriptions->listing(
            $principal->userId(),
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 20),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'definition_code' => ['required', 'string', 'max:80'],
            'recipient_user_id' => ['required', 'integer', 'min:1'],
            'frequency' => ['required', 'string', 'max:16'],
            'filters' => ['nullable', 'array'],
        ]);

        return $this->command(
            $request,
            new CommandDefinition(
                name: 'reporting.subscription.create',
                capability: 'reporting.subscription.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'reporting.subscription.created',
            ),
            $data,
            fn (int $actorUserId): array => $this->subscriptions->create($actorUserId, $data),
            201,
            'report_subscription',
        );
    }

    public function update(Request $request, int $subscriptionId): JsonResponse
    {
        $data = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'definition_code' => ['sometimes', 'string', 'max:80'],
            'recipient_user_id' => ['sometimes', 'integer', 'min:1'],
            'frequency' => ['sometimes', 'string', 'max:16'],
            'filters' => ['sometimes', 'array'],
        ]);

        return $this->command(
            $request,
            new CommandDefinition(
                name: 'reporting.subscription.update',
                capability: 'reporting.subscription.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'reporting.subscription.updated',
            ),
            ['subscription_id' => $subscriptionId, ...$data],
            fn (int $actorUserId): array => $this->subscriptions->update($actorUserId, $subscriptionId, $data),
            200,
            'report_subscription',
        );
    }

    public function destroy(Request $request, int $subscriptionId): JsonResponse
    {
        return $this->command(
            $request,
            new CommandDefinition(
                name: 'reporting.subscription.delete',
                capability: 'reporting.subscription.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'reporting.subscription.deleted',
            ),
            ['subscription_id' => $subscriptionId],
            fn (int $actorUserId): array => $this->subscriptions->delete($actorUserId, $subscriptionId),
            200,
            'report_subscription',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(int): array<string, mixed>  $operation
     */
    private function command(
        Request $request,
        CommandDefinition $definition,
        array $payload,
        Closure $operation,
        int $successStatus,
        string $resourceType,
    ): JsonResponse {
        $principal = $this->principals->resolve();
        $result = $this->commands->run(
            $definition,
            $payload,
            function () use ($operation, $principal, $successStatus, $resourceType): array {
                $body = $operation($principal->userId());

                return [
                    'http_status' => $successStatus,
                    'body' => $body,
                    'resource_type' => $resourceType,
                    'resource_id' => (string) ($body['id'] ?? ''),
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        $response = $result->status === 201
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
