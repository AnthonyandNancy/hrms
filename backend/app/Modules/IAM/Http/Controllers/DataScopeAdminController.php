<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Authorization\DataScopeTemplateCatalog;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Writes only select a code-owned ScopeTemplate. Arbitrary predicates are
 * rejected before any storage or model access.
 */
final class DataScopeAdminController
{
    public function __construct(
        private readonly AuthenticationContext $context,
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
            ...$this->singleCompanyRules([]),
        ]);

        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $items = app(\App\Modules\IAM\Services\DataScopePolicyService::class)->rows();
        $total = count($items);

        return $this->projections->ok(new ProjectionResult(
            items: array_slice($items, ($page - 1) * $pageSize, $pageSize),
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $this->businessDates->today()->value(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'iam_data_scope_policies'],
        ));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $principal = $this->context->requirePrincipal();

        $arbitrary = array_values(array_filter(
            ['predicate', 'sql', 'condition'],
            static fn (string $field): bool => array_key_exists($field, $request->all()),
        ));

        if ($arbitrary !== []) {
            throw new ValidationException(
                [$arbitrary[0] => ['Arbitrary predicates are not accepted. Select a code-owned scope template instead.']],
                'The given data was invalid.',
                ['reason' => 'ARBITRARY_PREDICATE_FORBIDDEN'],
            );
        }

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'min:1', 'max:120'],
            'anchor_type' => ['sometimes', 'string', 'max:24'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'org_unit_ids' => ['sometimes', 'array', 'max:200'],
            'org_unit_ids.*' => ['integer', 'min:1'],
            'include_descendants' => ['sometimes', 'boolean'],
            ...$this->singleCompanyRules(['label', 'anchor_type', 'active', 'sort_order', 'org_unit_ids', 'include_descendants']),
        ]);

        // Arbitrary predicates are refused with a specific reason code before
        // the whitelist rule turns them into a generic 422.
        $arbitrary = array_values(array_filter(
            ['predicate', 'sql', 'condition'],
            static fn (string $field): bool => array_key_exists($field, $request->all()),
        ));

        if ($arbitrary !== []) {
            throw new ValidationException(
                [$arbitrary[0] => ['Arbitrary predicates are not accepted. Select a code-owned scope template instead.']],
                'The given data was invalid.',
                ['reason' => 'ARBITRARY_PREDICATE_FORBIDDEN'],
            );
        }

        $template = DataScopeTemplateCatalog::find($id);

        if ($template === null) {
            throw new ValidationException(
                ['template' => ['The scope template is not declared in the code-owned catalog.']],
                'The given data was invalid.',
                ['reason' => 'UNKNOWN_SCOPE_TEMPLATE'],
            );
        }

        $anchor = AnchorType::tryFrom(strtoupper((string) ($data['anchor_type'] ?? $template['anchor_type'])));

        if ($anchor === null || $anchor->value !== $template['anchor_type']) {
            throw new ValidationException(
                ['anchor_type' => ['The anchor type must match the scope template.']],
                'The given data was invalid.',
                ['reason' => 'SCOPE_TEMPLATE_ANCHOR_MISMATCH'],
            );
        }

        $payload = [
            'template' => (string) $template['template'],
            'anchor_type' => $template['anchor_type'],
            'label' => (string) ($data['label'] ?? $template['label']),
            'active' => (bool) ($data['active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        $result = $this->commands->run(
            definition: $this->definition(),
            payload: $payload,
            operation: function () use ($payload): array {
                $row = app(\App\Modules\IAM\Services\DataScopePolicyService::class)->upsert($payload);

                return [
                    'http_status' => 200,
                    'body' => $row,
                    'resource_type' => 'data_scope_policy',
                    'resource_id' => (string) $row['template'],
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
     * Single-company deployment: the request BODY is whitelisted, so any field
     * outside that whitelist is rejected. Query-string parameters are governed
     * by their own explicit rules above and are never inspected here.
     *
     * @param  list<string>  $allowed
     * @return array<string, list<string>>
     */
    private function singleCompanyRules(array $allowed): array
    {
        $rules = [];
        foreach (array_keys(request()->request->all()) as $field) {
            if (! in_array($field, $allowed, true)) {
                $rules[(string) $field] = ['prohibited'];
            }
        }

        return $rules;
    }

    private function definition(): CommandDefinition
    {
        return new CommandDefinition(
            name: 'iam.data_scope.upsert',
            capability: 'iam.scope.manage',
            anchor: AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'iam.data_scope.updated',
        );
    }
}
