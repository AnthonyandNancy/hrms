<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\TravelService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Time\TenantBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Travel policies are commands plus projections. Reads go through
 * ProjectionResponder; writes use CommandRunner and require Idempotency-Key.
 */
final class TravelPolicyController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly TenantBusinessDate $businessDates,
        private readonly TravelService $travel,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'city_tier' => ['sometimes', 'nullable', 'string', 'max:8'],
            'as_of' => ['sometimes', 'nullable', new BusinessDateRule],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $legalEntityId = (int) $data['legal_entity_id'];
        $asOf = $this->businessDates->resolve($data['as_of'] ?? null, $actor->tenantId)->value();
        $this->scope->assertLegalEntity($actor, $legalEntityId, $asOf);

        return $this->projections->ok($this->travel->policyProjection(
            tenantId: $actor->tenantId,
            legalEntityId: $legalEntityId,
            cityTier: isset($data['city_tier']) ? (string) $data['city_tier'] : null,
            asOf: $asOf,
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['page_size'] ?? 20),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'city_tier' => ['required', 'string', 'max:8'],
            'hotel_cap_cents' => ['required', 'integer', 'min:0'],
            'transport_cap_cents' => ['sometimes', 'integer', 'min:0'],
            'effective_from' => ['required', new BusinessDateRule],
        ]);

        $legalEntityId = (int) $data['legal_entity_id'];
        $this->scope->assertLegalEntity($actor, $legalEntityId, (string) $data['effective_from']);

        $payload = [
            'legal_entity_id' => $legalEntityId,
            'city_tier' => strtoupper((string) $data['city_tier']),
            'hotel_cap_cents' => (int) $data['hotel_cap_cents'],
            'transport_cap_cents' => (int) ($data['transport_cap_cents'] ?? 0),
            'effective_from' => (string) $data['effective_from'],
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.travel.policy.publish',
                capability: 'time.travel.policy.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.travel.policy.published',
            ),
            $payload,
            function () use ($actor, $payload): array {
                $body = $this->travel->publishPolicy($actor->tenantId, $payload, $actor->userId);

                return [
                    'http_status' => 201,
                    'body' => $body,
                    'resource_type' => 'travel_policy',
                    'resource_id' => (string) $body['id'],
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    private function respond(CommandResult $result): JsonResponse
    {
        $response = $result->status === 201
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
