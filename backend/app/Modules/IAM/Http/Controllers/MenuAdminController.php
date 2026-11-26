<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Authorization\PermissionResourceCatalog;
use App\Modules\Platform\Contracts\MenuOverrideWriter;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MenuAdminController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly PermissionResourceCatalog $catalog,
        private readonly MenuOverrideWriter $overrides,
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
            'page_code' => ['sometimes', 'nullable', 'string', 'max:120'],
            ...$this->singleCompanyRules(),
        ]);

        $rows = $this->overrides->overrides();

        if (isset($data['page_code'])) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['page_code'] === (string) $data['page_code']));
        }

        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $total = count($rows);
        $items = array_slice($rows, ($page - 1) * $pageSize, $pageSize);

        return $this->projections->ok(new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $this->businessDates->today()->value(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'menu_overrides'],
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $data = $request->validate([
            'page_code' => ['required', 'string', 'max:120'],
            'label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'visible' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            ...$this->singleCompanyRules(),
        ]);

        $this->assertKnownPage((string) $data['page_code']);
        $payload = [
            'page_code' => (string) $data['page_code'],
            'label' => $data['label'] ?? null,
            'visible' => (bool) ($data['visible'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        $result = $this->commands->run(
            definition: $this->definition('iam.menu.override.create', 'iam.menu.override.created'),
            payload: $payload,
            operation: function () use ($payload): array {
                $override = $this->overrides->create($payload);

                return [
                    'http_status' => 201,
                    'body' => $override,
                    'resource_type' => 'menu_override',
                    'resource_id' => (string) $override['page_code'],
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result, created: true);
    }

    public function update(Request $request, string $pageCode): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'visible' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            ...$this->singleCompanyRules(),
        ]);

        $this->assertKnownPage($pageCode);
        $payload = [
            'page_code' => $pageCode,
            ...$data,
        ];

        $result = $this->commands->run(
            definition: $this->definition('iam.menu.override.update', 'iam.menu.override.updated'),
            payload: $payload,
            operation: function () use ($payload): array {
                $override = $this->overrides->update((string) $payload['page_code'], $payload);

                return [
                    'http_status' => 200,
                    'body' => $override,
                    'resource_type' => 'menu_override',
                    'resource_id' => (string) $override['page_code'],
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result, created: false);
    }

    public function destroy(Request $request, string $pageCode): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $request->validate($this->singleCompanyRules());
        $this->assertKnownPage($pageCode);

        $result = $this->commands->run(
            definition: $this->definition('iam.menu.override.delete', 'iam.menu.override.deleted'),
            payload: ['page_code' => $pageCode],
            operation: function () use ($pageCode): array {
                $this->overrides->delete($pageCode);

                return [
                    'http_status' => 200,
                    'body' => ['page_code' => $pageCode, 'deleted' => true],
                    'resource_type' => 'menu_override',
                    'resource_id' => $pageCode,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result, created: false);
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

    private function assertKnownPage(string $pageCode): void
    {
        if ($this->catalog->find($pageCode) === null) {
            throw new ValidationException(
                ['page_code' => ['The page code is not registered in the code-owned permission catalog.']],
                'The given data was invalid.',
                ['reason' => 'UNKNOWN_PAGE_CODE'],
            );
        }
    }

    private function definition(string $name, string $auditAction): CommandDefinition
    {
        return new CommandDefinition(
            name: $name,
            capability: 'iam.menu.manage',
            anchor: AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: $auditAction,
        );
    }

    private function respond(CommandResult $result, bool $created): JsonResponse
    {
        $response = $created && ! $result->replayed
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
