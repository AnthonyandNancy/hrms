<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Authorization\SensitiveFieldCatalog;
use App\Modules\IAM\Services\FieldPolicyService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FieldPolicyAdminController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly FieldPolicyService $policies,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ...$this->singleCompanyRules(),
        ]);

        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $items = $this->policies->rows();

        return $this->projections->ok(new ProjectionResult(
            items: array_slice($items, ($page - 1) * $pageSize, $pageSize),
            page: $page,
            pageSize: $pageSize,
            total: count($items),
            asOf: $this->businessDates->today()->value(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'iam_field_policies'],
        ));
    }

    public function update(Request $request, string $fieldKey): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $data = $request->validate([
            'field_profile' => ['required', 'string', 'max:24'],
            ...$this->singleCompanyRules(),
        ]);

        if (SensitiveFieldCatalog::declared($fieldKey) === null) {
            throw new ValidationException(
                ['field_key' => ['The field is not declared in the code-owned sensitive field catalog.']],
                'The given data was invalid.',
                ['reason' => 'UNKNOWN_FIELD_POLICY'],
            );
        }

        if (FieldProfile::tryFrom((string) $data['field_profile']) === null) {
            throw new ValidationException(
                ['field_profile' => ['Unknown field profile.']],
                'The given data was invalid.',
                ['reason' => 'UNKNOWN_FIELD_PROFILE'],
            );
        }

        $payload = ['field_key' => $fieldKey, 'field_profile' => (string) $data['field_profile']];

        $result = $this->commands->run(
            definition: new CommandDefinition(
                name: 'iam.field_policy.update',
                capability: 'iam.field.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'iam.field_policy.updated',
            ),
            payload: $payload,
            operation: function () use ($fieldKey, $data): array {
                $row = $this->policies->update($fieldKey, (string) $data['field_profile']);

                return [
                    'http_status' => 200,
                    'body' => $row,
                    'resource_type' => 'field_policy',
                    'resource_id' => $fieldKey,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        $response = ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * Single-company deployment: a client must never send a tenant, company or
     * legal-entity dimension. Reject these parameters instead of silently
     * ignoring them, so an operator cannot believe a narrower scope was saved.
     *
     * @return array<string, list<string>>
     */
    private function singleCompanyRules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'legal_entity_id' => ['prohibited'],
        ];
    }
}
