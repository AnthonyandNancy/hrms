<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\EmploymentContract;
use App\Modules\People\Services\ContractLifecycleService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Time\TenantBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
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
        private readonly TenantBusinessDate $businessDates,
        private readonly ContractLifecycleService $contracts,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
    ) {}

    public function contracts(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'legal_entity_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(EmploymentContract::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $asOf = $this->businessDates->resolve($data['as_of'] ?? null, $principal->tenantId());

        return $this->projections->ok($this->contracts->contracts(
            $principal,
            $this->authorizer,
            $asOf->value(),
            [
                'legal_entity_id' => isset($data['legal_entity_id']) ? (int) $data['legal_entity_id'] : null,
                'status' => $data['status'] ?? null,
            ],
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 20),
        ));
    }

    public function amendments(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->today($principal->tenantId());
        $this->assertContractAccess($principal, $id, 'people.contract.read', $asOf->value());

        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $resolvedAsOf = $this->businessDates->resolve($data['as_of'] ?? null, $principal->tenantId());

        return $this->projections->ok($this->contracts->amendmentHistory(
            $principal->tenantId(),
            $id,
            $resolvedAsOf->value(),
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 20),
        ));
    }

    public function amend(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->today($principal->tenantId());
        $this->assertContractAccess($principal, $id, 'people.contract.amend', $asOf->value());

        $data = $request->validate([
            'effective_date' => ['required', new BusinessDateRule],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.contract_type' => ['sometimes', Rule::in(EmploymentContract::TYPES)],
            'changes.end_date' => ['sometimes', 'nullable', new BusinessDateRule],
            'changes.signed_on' => ['sometimes', 'nullable', new BusinessDateRule],
            'changes.terms_summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'changes.template_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
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
            fn (): array => $this->contracts->amend($principal->tenantId(), $id, $data, $principal->userId()),
            201,
            'employment_contract',
            (string) $id,
        );
    }

    public function terminate(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->today($principal->tenantId());
        $this->assertContractAccess($principal, $id, 'people.contract.terminate', $asOf->value());

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
            fn (): array => $this->contracts->terminate($principal->tenantId(), $id, $data, $principal->userId()),
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
            fn (): array => $this->contracts->softDelete($principal->tenantId(), $id, (string) $data['reason'], $principal->userId()),
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
            fn (): array => $this->contracts->restore($principal->tenantId(), $id, $principal->userId()),
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
            'status' => ['nullable', Rule::in(['DRAFT', 'PUBLISHED', 'ARCHIVED'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $asOf = $this->businessDates->resolve($data['as_of'] ?? null, $principal->tenantId());

        return $this->projections->ok($this->contracts->templates(
            $principal,
            $this->authorizer,
            $asOf->value(),
            [
                'code' => $data['code'] ?? null,
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
            fn (): array => $this->contracts->createTemplate($principal->tenantId(), $data, $principal->userId()),
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
            fn (): array => $this->contracts->publishTemplate($principal->tenantId(), $id, $principal->userId()),
            200,
            'contract_template',
            (string) $id,
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

    private function assertContractAccess(Principal $principal, int $contractId, string $capability, string $on): void
    {
        $contract = EmploymentContract::query()
            ->where('tenant_id', $principal->tenantId())
            ->whereKey($contractId)
            ->first();

        if ($contract === null) {
            throw new NotFoundOrForbiddenException;
        }

        $checks = [
            AnchorRef::self(),
            AnchorRef::managed(),
            AnchorRef::company(),
        ];

        $assignment = Assignment::query()
            ->where('tenant_id', $principal->tenantId())
            ->where('employment_id', $contract->employment_id)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        if ($assignment !== null) {
            $checks[] = AnchorRef::orgUnit((int) $assignment->org_unit_id);
            $checks[] = AnchorRef::orgSubtree((int) $assignment->org_unit_id);
        }

        foreach ($checks as $anchor) {
            if ($this->authorizer->allows($principal, $capability, $anchor, $on)) {
                return;
            }
        }

        throw new AuthorizationException('Forbidden.');
    }
}
