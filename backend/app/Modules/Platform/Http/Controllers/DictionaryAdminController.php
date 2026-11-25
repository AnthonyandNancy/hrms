<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Models\DictionaryEntry;
use App\Modules\Platform\Models\SystemParameter;
use App\Modules\Platform\Services\AuditService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\RequestContext;
use App\Shared\Time\Clock;
use App\Shared\Time\TenantBusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Dictionary and parameter authoring lives in Platform because Platform owns
 * both storages. Every write validates the storage shape before persisting,
 * and sensitive parameter values are encrypted at rest.
 */
final class DictionaryAdminController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
        private readonly TenantBusinessDate $businessDates,
        private readonly AuditService $audit,
        private readonly Clock $clock,
    ) {}

    public function dictionaries(Request $request): JsonResponse
    {
        $tenantId = $this->principals->resolve()->tenantId();
        $data = $request->validate([
            'type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DictionaryEntry::query()->where('tenant_id', $tenantId);

        if (isset($data['type'])) {
            $query->where('type', (string) $data['type']);
        }

        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $total = (clone $query)->count();
        $items = $query
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->forPage($page, $pageSize)
            ->get()
            ->map(fn (DictionaryEntry $entry): array => $this->dictionaryRow($entry))
            ->all();

        return $this->projections->ok(new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $this->businessDates->today($tenantId)->value(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'sys_dictionaries', 'filters' => ['type' => $data['type'] ?? null]],
        ));
    }

    public function storeDictionary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:80', 'regex:/^[A-Z][A-Z0-9_]{1,79}$/'],
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Z][A-Z0-9_]{0,79}$/'],
            'label' => ['required', 'string', 'max:160'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $result = $this->commands->run(
            definition: $this->dictionaryDefinition('system.dictionary.create', 'system.dictionary.created'),
            payload: $data,
            operation: function () use ($data): array {
                $tenantId = $this->principals->resolve()->tenantId();
                $exists = DictionaryEntry::query()
                    ->where('tenant_id', $tenantId)
                    ->where('type', (string) $data['type'])
                    ->where('code', (string) $data['code'])
                    ->exists();

                if ($exists) {
                    throw new ConflictException('This dictionary entry already exists.', ['reason' => 'DICTIONARY_ENTRY_EXISTS']);
                }

                /** @var DictionaryEntry $entry */
                $entry = DictionaryEntry::query()->create([
                    'tenant_id' => $tenantId,
                    'type' => (string) $data['type'],
                    'code' => (string) $data['code'],
                    'label' => (string) $data['label'],
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                    'active' => (bool) ($data['active'] ?? true),
                ]);

                $row = $this->dictionaryRow($entry);
                $this->audit->record($tenantId, RequestContext::userId(), 'system.dictionary.created', 'dictionary_entry', $entry->id, [], $row);

                return [
                    'http_status' => 201,
                    'body' => $row,
                    'resource_type' => 'dictionary_entry',
                    'resource_id' => (string) $entry->id,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result, created: true);
    }

    public function updateDictionary(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:160'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $result = $this->commands->run(
            definition: $this->dictionaryDefinition('system.dictionary.update', 'system.dictionary.updated'),
            payload: ['id' => $id, ...$data],
            operation: function () use ($data, $id): array {
                $tenantId = $this->principals->resolve()->tenantId();
                /** @var DictionaryEntry|null $entry */
                $entry = DictionaryEntry::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($id)
                    ->first();

                if ($entry === null) {
                    throw new NotFoundOrForbiddenException;
                }

                $before = $this->dictionaryRow($entry);
                $entry->fill([
                    ...array_key_exists('label', $data) ? ['label' => (string) $data['label']] : [],
                    ...array_key_exists('sort_order', $data) ? ['sort_order' => (int) $data['sort_order']] : [],
                    ...array_key_exists('active', $data) ? ['active' => (bool) $data['active']] : [],
                ])->save();

                $row = $this->dictionaryRow($entry->refresh());
                $this->audit->record($tenantId, RequestContext::userId(), 'system.dictionary.updated', 'dictionary_entry', $id, $before, $row);

                return [
                    'http_status' => 200,
                    'body' => $row,
                    'resource_type' => 'dictionary_entry',
                    'resource_id' => (string) $id,
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

    public function parameters(Request $request): JsonResponse
    {
        $tenantId = $this->principals->resolve()->tenantId();
        $data = $request->validate([
            'key' => ['sometimes', 'nullable', 'string', 'max:120'],
            'scope' => ['sometimes', 'nullable', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SystemParameter::query()->where('tenant_id', $tenantId);

        if (isset($data['key'])) {
            $query->where('key', (string) $data['key']);
        }

        if (isset($data['scope'])) {
            $query->where('scope', (string) $data['scope']);
        }

        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $total = (clone $query)->count();
        $items = $query
            ->orderBy('key')
            ->orderBy('scope')
            ->forPage($page, $pageSize)
            ->get()
            ->map(fn (SystemParameter $parameter): array => $this->parameterRow($parameter))
            ->all();

        return $this->projections->ok(new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $this->businessDates->today($tenantId)->value(),
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'sys_parameters'],
        ));
    }

    public function updateParameter(Request $request): JsonResponse
    {
        $raw = $request->all();

        if (! array_key_exists('value', $raw) || array_key_exists('values', $raw)) {
            throw new ValidationException(
                ['value' => ['Parameters must be stored as a JSON object containing exactly one value key.']],
                'The given data was invalid.',
                ['reason' => 'INVALID_PARAMETER_SHAPE'],
            );
        }

        if (array_key_exists('predicate', $raw) || array_key_exists('condition', $raw) || array_key_exists('sql', $raw)) {
            throw new ValidationException(
                ['value' => ['Parameters cannot carry predicates or executable conditions.']],
                'The given data was invalid.',
                ['reason' => 'INVALID_PARAMETER_SHAPE'],
            );
        }

        $data = $request->validate([
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z][a-z0-9_.-]{1,119}$/'],
            'scope' => ['sometimes', 'string', 'max:80'],
            'value' => ['present'],
            'sensitive' => ['sometimes', 'boolean'],
        ]);

        $scope = (string) ($data['scope'] ?? 'TENANT');
        $this->assertScope($scope);

        $payload = [
            'key' => (string) $data['key'],
            'scope' => $scope,
            'value' => $data['value'],
            'sensitive' => (bool) ($data['sensitive'] ?? false),
        ];

        $result = $this->commands->run(
            definition: $this->parameterDefinition(),
            payload: $payload,
            operation: function () use ($payload): array {
                $tenantId = $this->principals->resolve()->tenantId();
                $shape = ['value' => $payload['value']];
                $stored = $payload['sensitive']
                    ? Crypt::encryptString(json_encode($shape, JSON_THROW_ON_ERROR))
                    : $shape;

                /** @var SystemParameter|null $existing */
                $existing = SystemParameter::query()
                    ->where('tenant_id', $tenantId)
                    ->where('key', $payload['key'])
                    ->where('scope', $payload['scope'])
                    ->first();

                $before = $existing === null ? [] : ['sensitive' => (bool) $existing->sensitive, 'scope' => (string) $existing->scope];

                /** @var SystemParameter $parameter */
                $parameter = SystemParameter::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'key' => $payload['key'], 'scope' => $payload['scope']],
                    ['value_json' => $stored, 'sensitive' => $payload['sensitive']],
                );

                // A sensitive parameter's value is never returned or audited.
                $row = $this->parameterRow($parameter->refresh());
                $this->audit->record(
                    $tenantId,
                    RequestContext::userId(),
                    'system.parameter.updated',
                    'system_parameter',
                    $parameter->id,
                    $before,
                    ['sensitive' => (bool) $parameter->sensitive, 'scope' => (string) $parameter->scope],
                    ['request_id' => RequestContext::requestId()],
                );

                return [
                    'http_status' => 200,
                    'body' => $row,
                    'resource_type' => 'system_parameter',
                    'resource_id' => (string) $parameter->id,
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

    private function assertScope(string $scope): void
    {
        if ($scope === 'TENANT') {
            return;
        }

        if (preg_match('/^LEGAL_ENTITY:([1-9][0-9]{0,18})$/', $scope) === 1) {
            return;
        }

        throw new ValidationException(
            ['scope' => ['Parameter scope must be TENANT or LEGAL_ENTITY:{id}.']],
            'The given data was invalid.',
            ['reason' => 'INVALID_PARAMETER_SCOPE'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dictionaryRow(DictionaryEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'type' => (string) $entry->type,
            'code' => (string) $entry->code,
            'label' => (string) $entry->label,
            'sort_order' => (int) $entry->sort_order,
            'active' => (bool) $entry->active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parameterRow(SystemParameter $parameter): array
    {
        $payload = $parameter->decodedValue();
        $sensitive = (bool) $parameter->sensitive;

        return [
            'id' => (int) $parameter->id,
            'key' => (string) $parameter->key,
            'scope' => (string) $parameter->scope,
            'value' => $sensitive ? null : ($payload['value'] ?? null),
            'sensitive' => $sensitive,
            'masked' => $sensitive,
        ];
    }

    private function dictionaryDefinition(string $name, string $auditAction): CommandDefinition
    {
        return new CommandDefinition(
            name: $name,
            capability: 'system.dictionary.manage',
            anchor: AnchorType::LEGAL_ENTITY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: $auditAction,
        );
    }

    private function parameterDefinition(): CommandDefinition
    {
        return new CommandDefinition(
            name: 'system.parameter.upsert',
            capability: 'system.parameter.manage',
            anchor: AnchorType::LEGAL_ENTITY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'system.parameter.updated',
        );
    }

    private function respond(\App\Shared\Commands\CommandResult $result, bool $created): JsonResponse
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
