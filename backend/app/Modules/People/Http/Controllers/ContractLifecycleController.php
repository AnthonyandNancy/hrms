<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Contracts\ContractAccess;
use App\Modules\People\Contracts\ContractFieldPolicy;
use App\Modules\People\Models\EmploymentContract;
use App\Modules\People\Services\ContractLifecycleService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HTTP shell for the contract lifecycle. Reads are projections; every write is
 * an idempotent CommandRunner command with a required Idempotency-Key.
 */
final class ContractLifecycleController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly ContractLifecycleService $contracts,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
        private readonly ContractAccess $access,
        private readonly ContractFieldPolicy $fields,
    ) {}

    public function contracts(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'status' => ['nullable', Rule::in(EmploymentContract::STATUSES)],
            'q' => ['nullable', 'string', 'max:120'],
            'employment_id' => ['nullable', 'integer', 'min:1'],
            'expiry_window' => ['nullable', Rule::in(['30', '60', '7', 'EXPIRED'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $asOf = $this->businessDates->resolve($data['as_of'] ?? null);
        $window = isset($data['expiry_window'])
            ? (string) $data['expiry_window']
            : null;

        $result = $this->contracts->contracts(
            $principal,
            $this->authorizer,
            $asOf->value(),
            [
                'status' => $data['status'] ?? null,
                'q' => $data['q'] ?? null,
                'employment_id' => isset($data['employment_id']) ? (int) $data['employment_id'] : null,
                'expiring_within_days' => $window !== null && $window !== 'EXPIRED' ? (int) $window : null,
                'expired' => $window === 'EXPIRED' ? true : null,
            ],
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 20),
        );

        return $this->projections->ok($this->projectRows($result, $principal, $asOf->value()));
    }

    public function amendments(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->today();
        $contract = $this->access->contract($principal, $id, 'people.contract.read', $asOf->value());

        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $resolvedAsOf = $this->businessDates->resolve($data['as_of'] ?? null);

        return $this->projections->ok($this->projectHistory(
            $this->contracts->amendmentHistory(
                $id,
                $resolvedAsOf->value(),
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 20),
            ),
            $principal,
            $contract,
            $resolvedAsOf->value(),
        ));
    }

    public function amend(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->today();
        $this->access->contract($principal, $id, 'people.contract.amend', $asOf->value());

        $data = $request->validate([
            'effective_date' => ['required', new BusinessDateRule],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.contract_type' => ['sometimes', Rule::in(EmploymentContract::TYPES)],
            'changes.end_date' => ['sometimes', 'nullable', new BusinessDateRule],
            'changes.signed_on' => ['sometimes', 'nullable', new BusinessDateRule],
            'changes.terms_summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'changes.template_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'changes.work_location' => ['sometimes', 'nullable', 'string', 'max:160'],
            'changes.probation_end_date' => ['sometimes', 'nullable', new BusinessDateRule],
        ]);

        // Laravel drops unvalidated nested keys from validated(), which would
        // hide an unsupported amendment field. Keep the raw map so the service
        // can reject unknown keys explicitly with a stable 422.
        $data['changes'] = (array) $request->input('changes', $data['changes'] ?? []);

        return $this->command(
            $request,
            new CommandDefinition(
                name: 'people.contract.amend',
                capability: 'people.contract.amend',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'people.contract.amended',
            ),
            ['contract_id' => $id, ...$data],
            fn (): array => $this->projectMutation($this->contracts->amend($id, $data, $principal->userId()), $principal, (string) $data['effective_date']),
            201,
            'employment_contract',
            (string) $id,
        );
    }

    public function terminate(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->today();
        $this->access->contract($principal, $id, 'people.contract.terminate', $asOf->value());

        $data = $request->validate([
            'effective_date' => ['required', new BusinessDateRule],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->command(
            $request,
            new CommandDefinition(
                name: 'people.contract.terminate',
                capability: 'people.contract.terminate',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'people.contract.terminated',
            ),
            ['contract_id' => $id, ...$data],
            fn (): array => $this->projectMutation($this->contracts->terminate($id, $data, $principal->userId()), $principal, (string) $data['effective_date']),
            200,
            'employment_contract',
            (string) $id,
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->command(
            $request,
            new CommandDefinition(
                name: 'people.contract.soft-delete',
                capability: 'people.contract.delete',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'people.contract.soft_deleted',
            ),
            ['contract_id' => $id, ...$data],
            fn (): array => $this->contracts->softDelete($id, (string) $data['reason'], $principal->userId()),
            200,
            'employment_contract',
            (string) $id,
        );
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();

        return $this->command(
            $request,
            new CommandDefinition(
                name: 'people.contract.restore',
                capability: 'people.contract.restore',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'people.contract.restored',
            ),
            ['contract_id' => $id],
            fn (): array => $this->contracts->restore($id, $principal->userId()),
            200,
            'employment_contract',
            (string) $id,
        );
    }

    public function templates(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'code' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['DRAFT', 'PUBLISHED', 'ARCHIVED'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $asOf = $this->businessDates->resolve($data['as_of'] ?? null);

        return $this->projections->ok($this->contracts->templates(
            $principal,
            $this->authorizer,
            $asOf->value(),
            [
                'code' => $data['code'] ?? null,
                'q' => $data['q'] ?? null,
                'status' => $data['status'] ?? null,
            ],
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 20),
        ));
    }

    public function createTemplate(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'name' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        return $this->command(
            $request,
            new CommandDefinition(
                name: 'people.contract.template.create',
                capability: 'people.contract.template.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'people.contract.template.created',
            ),
            $data,
            fn (): array => $this->contracts->createTemplate($data, $principal->userId()),
            201,
            'contract_template',
            '',
        );
    }

    public function publishTemplate(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();

        return $this->command(
            $request,
            new CommandDefinition(
                name: 'people.contract.template.publish',
                capability: 'people.contract.template.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'people.contract.template.published',
            ),
            ['template_id' => $id],
            fn (): array => $this->contracts->publishTemplate($id, $principal->userId()),
            200,
            'contract_template',
            (string) $id,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function projectMutation(array $result, Principal $principal, string $asOf): array
    {
        $contract = EmploymentContract::query()->whereKey((int) ($result['contract']['id'] ?? 0))->first();

        if ($contract instanceof EmploymentContract && isset($result['contract']) && is_array($result['contract'])) {
            $result['contract'] = $this->fields->project($principal, $contract, $result['contract'], $asOf);
        }

        if (isset($result['version']) && is_array($result['version'])) {
            $result['version'] = $this->fields->projectSnapshot($principal, $contract, $result['version'], $asOf);
        }

        return $result;
    }

    private function projectHistory(
        \App\Shared\Projections\ProjectionResult $result,
        Principal $principal,
        EmploymentContract $contract,
        string $asOf,
    ): \App\Shared\Projections\ProjectionResult {
        $items = array_map(function (array $item) use ($principal, $contract, $asOf): array {
            if (isset($item['snapshot']) && is_array($item['snapshot'])) {
                $item['snapshot'] = $this->fields->projectSnapshot($principal, $contract, $item['snapshot'], $asOf);
            }

            return $item;
        }, $result->items);

        return new \App\Shared\Projections\ProjectionResult(
            items: $items,
            page: $result->page,
            pageSize: $result->pageSize,
            total: $result->total,
            asOf: $result->asOf,
            freshness: $result->freshness,
            sourceHash: $result->sourceHash,
            partial: $result->partial,
            partialReason: $result->partialReason,
            drilldown: $result->drilldown,
        );
    }

    private function projectRows(\App\Shared\Projections\ProjectionResult $result, Principal $principal, string $asOf): \App\Shared\Projections\ProjectionResult
    {
        $items = array_map(function (array $item) use ($principal, $asOf): array {
            $contract = EmploymentContract::query()->whereKey((int) ($item['id'] ?? 0))->first();

            return $contract instanceof EmploymentContract
                ? $this->fields->project($principal, $contract, $item, $asOf)
                : $item;
        }, $result->items);

        return new \App\Shared\Projections\ProjectionResult(
            items: $items,
            page: $result->page,
            pageSize: $result->pageSize,
            total: $result->total,
            asOf: $result->asOf,
            freshness: $result->freshness,
            sourceHash: $result->sourceHash,
            partial: $result->partial,
            partialReason: $result->partialReason,
            drilldown: $result->drilldown,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): array<string, mixed>  $operation
     */
    private function command(
        Request $request,
        CommandDefinition $definition,
        array $payload,
        Closure $operation,
        int $successStatus,
        string $resourceType,
        string $resourceId,
    ): JsonResponse {
        $result = $this->commands->run(
            $definition,
            $payload,
            function () use ($operation, $successStatus, $resourceType, $resourceId): array {
                $body = $operation();

                return [
                    'http_status' => $successStatus,
                    'body' => $body,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId !== '' ? $resourceId : (string) ($body['id'] ?? ''),
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
