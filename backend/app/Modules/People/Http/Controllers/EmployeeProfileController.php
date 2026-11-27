<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Models\EmployeeChangeRequest;
use App\Modules\People\Models\Employment;
use App\Modules\People\Services\EmployeeProfileService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Employee-profile subresources. Read uses people.employee.read; every write is
 * a CommandRunner command (capability, idempotency, invariants and audit) with
 * a required Idempotency-Key. Every employment id is re-scoped through the
 * authorizer before any row is touched.
 */
final class EmployeeProfileController
{
    private const RESOURCES = [
        'education' => ['read' => 'people.employee.read', 'write' => 'people.employee.manage'],
        'experiences' => ['read' => 'people.employee.read', 'write' => 'people.employee.manage'],
        'contacts' => ['read' => 'people.employee.read', 'write' => 'people.employee.manage'],
        'certificates' => ['read' => 'people.employee.read', 'write' => 'people.employee.manage'],
        'change_requests' => ['read' => 'people.employee.read', 'write' => 'people.employee.manage'],
    ];

    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly EmployeeProfileService $profiles,
        private readonly CommandRunner $commands,
    ) {}

    public function index(Request $request, int $employmentId): JsonResponse
    {
        $resource = (string) $request->route('profileResource', '');
        $principal = $this->principals->resolve();
        $this->assertEmploymentVisible($principal, $employmentId);
        $this->assertPermits($principal, $this->config($resource)['read']);

        $items = $this->profiles->list($resource, $employmentId);

        if ($resource === 'change_requests') {
            $items = $this->withChangeDecisions($items);
        }

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request, int $employmentId): JsonResponse
    {
        $resource = (string) $request->route('profileResource', '');
        $principal = $this->principals->resolve();
        $this->assertEmploymentVisible($principal, $employmentId);
        $this->assertPermits($principal, $this->config($resource)['write']);
        $validated = $this->validate($request, $resource);

        return $this->command(
            $request,
            $principal,
            new CommandDefinition(
                name: "people.profile.{$resource}.open",
                capability: 'people.employee.manage',
                anchor: \App\Shared\Authorization\AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'people.profile.opened',
            ),
            ['employment_id' => $employmentId, 'resource' => $resource, 'data' => $validated],
            fn (): array => $this->profiles->open($resource, $employmentId, $validated, $principal->userId()),
            201,
            'employee_profile',
        );
    }

    public function update(Request $request, int $employmentId, int $id): JsonResponse
    {
        $resource = (string) $request->route('profileResource', '');
        $principal = $this->principals->resolve();
        $this->assertEmploymentVisible($principal, $employmentId);
        $this->assertPermits($principal, $this->config($resource)['write']);
        $validated = $this->validate($request, $resource, true);

        return $this->command(
            $request,
            $principal,
            new CommandDefinition(
                name: "people.profile.{$resource}.revise",
                capability: 'people.employee.manage',
                anchor: \App\Shared\Authorization\AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'people.profile.revised',
            ),
            ['employment_id' => $employmentId, 'resource' => $resource, 'id' => $id, 'data' => $validated],
            function () use ($resource, $employmentId, $id, $validated, $principal): array {
                if ($resource === 'change_requests') {
                    $this->assertChangeRequestDraft($employmentId, $id);

                    return $this->profiles->updateChangeRequestDraft(
                        $employmentId,
                        $id,
                        $validated,
                        $principal->userId(),
                    );
                }

                return $this->profiles->revise(
                    $resource,
                    $employmentId,
                    $id,
                    $validated,
                    $principal->userId(),
                );
            },
            200,
            'employee_profile',
            (string) $id,
        );
    }

    /**
     * Append-only retirement. The row is never deleted: superseded_at closes
     * its effective window and the response identifies the exact version.
     */
    public function retire(Request $request, int $employmentId, int $id): JsonResponse
    {
        $resource = (string) $request->route('profileResource', '');
        $principal = $this->principals->resolve();
        $this->assertEmploymentVisible($principal, $employmentId);
        $this->assertPermits($principal, $this->config($resource)['write']);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'effective_from' => ['nullable', new BusinessDateRule],
        ]);

        return $this->command(
            $request,
            $principal,
            new CommandDefinition(
                name: "people.profile.{$resource}.retire",
                capability: 'people.employee.manage',
                anchor: \App\Shared\Authorization\AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'people.profile.retired',
            ),
            [
                'employment_id' => $employmentId,
                'resource' => $resource,
                'id' => $id,
                'reason' => (string) $data['reason'],
                'effective_from' => $data['effective_from'] ?? null,
            ],
            fn (): array => $this->profiles->retire(
                $resource,
                $employmentId,
                $id,
                (string) $data['reason'],
                $data['effective_from'] ?? null,
                $principal->userId(),
            ),
            200,
            'employee_profile',
            (string) $id,
        );
    }

    public function submit(Request $request, int $employmentId, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertEmploymentVisible($principal, $employmentId);
        $this->assertPermits($principal, 'people.employee.manage');

        return $this->command(
            $request,
            $principal,
            new CommandDefinition(
                name: 'people.employee.change.submit',
                capability: 'people.employee.manage',
                anchor: \App\Shared\Authorization\AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'people.change.submitted',
            ),
            ['employment_id' => $employmentId, 'id' => $id],
            fn (): array => $this->profiles->submitChangeRequest(
                $employmentId,
                $id,
                $principal->userId(),
            ),
            200,
            'employee_change_request',
            (string) $id,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  \Closure(): array<string, mixed>  $operation
     */
    private function command(
        Request $request,
        Principal $principal,
        CommandDefinition $definition,
        array $payload,
        \Closure $operation,
        int $successStatus,
        string $resourceType,
        string $resourceId = '',
    ): JsonResponse {
        $result = $this->commands->run(
            definition: $definition,
            payload: $payload,
            operation: function () use ($operation, $successStatus, $resourceType, $resourceId): array {
                $body = $operation();
                $resolvedId = $resourceId !== '' ? $resourceId : (string) ($body['id'] ?? '');

                return [
                    'http_status' => $successStatus,
                    'body' => $body,
                    'resource_type' => $resourceType,
                    'resource_id' => $resolvedId,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        $response = $result->status === 201
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * A submitted (or otherwise decided) change request is immutable. This is
     * enforced before the generic profile writer so submitted content cannot
     * be amended even through the PATCH subresource.
     */
    private function assertChangeRequestDraft(int $employmentId, int $id): void
    {
        $request = EmployeeChangeRequest::query()
            ->where('employment_id', $employmentId)
            ->whereKey($id)
            ->first();

        if ($request === null) {
            throw new NotFoundOrForbiddenException;
        }

        if ((string) $request->getAttribute('status') !== 'DRAFT') {
            throw new \App\Shared\Exceptions\ConflictException(
                'Submitted change requests are read-only.',
                ['reason' => 'CHANGE_REQUEST_NOT_DRAFT'],
            );
        }
    }

    /**
     * The profile service owns the change-request write model but its list
     * projection predates the decision columns. Add only server-stored values;
     * when a decision is absent the fields stay null and are never inferred.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withChangeDecisions(array $items): array
    {
        $ids = array_values(array_filter(
            array_map(static fn (array $item): ?int => isset($item['id']) ? (int) $item['id'] : null, $items),
            static fn (?int $id): bool => $id !== null && $id > 0,
        ));

        if ($ids === []) {
            return $items;
        }

        $decisions = EmployeeChangeRequest::query()
            ->whereKey($ids)
            ->get(['id', 'decided_at', 'decided_by'])
            ->keyBy('id');

        return array_map(static function (array $item) use ($decisions): array {
            $decision = $decisions->get((int) ($item['id'] ?? 0));
            $item['decided_by'] = $decision?->getAttribute('decided_by') !== null
                ? (int) $decision->getAttribute('decided_by')
                : null;
            $item['decided_at'] = $decision?->getAttribute('decided_at') !== null
                ? CarbonImmutable::parse((string) $decision->getAttribute('decided_at'))->toIso8601ZuluString()
                : null;

            return $item;
        }, $items);
    }

    private function assertEmploymentVisible(Principal $principal, int $employmentId): void
    {
        $today = $this->businessDates->today()->value();
        $exists = Employment::query()
            ->whereKey($employmentId)
            ->exists();

        if (! $exists) {
            throw new NotFoundOrForbiddenException;
        }

        $visible = $this->authorizer->constrain(
            Employment::query()->whereKey($employmentId),
            $principal,
            'people.employee.read',
            $today,
        )->exists();

        if (! $visible) {
            throw new NotFoundOrForbiddenException;
        }
    }

    private function assertPermits(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today()->value();

        if (! $this->authorizer->permits($principal, $capability, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * @return array{read: string, write: string}
     */
    private function config(string $resource): array
    {
        if (! isset(self::RESOURCES[$resource])) {
            throw new NotFoundOrForbiddenException;
        }

        return self::RESOURCES[$resource];
    }

    /**
     * @return array<string, mixed>
     */
    private function validate(Request $request, string $resource, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate(match ($resource) {
            'education' => [
                'school' => [$required, 'string', 'max:160'],
                'degree' => [$required, 'string', 'max:80'],
                'major' => ['nullable', 'string', 'max:160'],
                'start_date' => [$required, 'date_format:Y-m-d'],
                'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'experiences' => [
                'company' => [$required, 'string', 'max:160'],
                'title' => [$required, 'string', 'max:160'],
                'start_date' => [$required, 'date_format:Y-m-d'],
                'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
                'leave_reason' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'contacts' => [
                'name' => [$required, 'string', 'max:120'],
                'relationship' => [$required, 'string', 'max:64'],
                'phone' => ['nullable', 'string', 'max:40'],
                'email' => ['nullable', 'email', 'max:255'],
                'is_emergency' => ['sometimes', 'boolean'],
                'is_primary' => ['sometimes', 'boolean'],
            ],
            'certificates' => [
                'certificate_type' => [$required, 'string', 'max:80'],
                'certificate_no' => ['nullable', 'string', 'max:120'],
                'issuer' => ['nullable', 'string', 'max:160'],
                'issued_on' => ['nullable', 'date_format:Y-m-d'],
                'expires_on' => ['nullable', 'date_format:Y-m-d'],
                'document_id' => ['nullable', 'integer', 'min:1'],
            ],
            'change_requests' => [
                'reason' => [$required, 'string', 'min:3', 'max:500'],
                'diff' => ['sometimes', 'array'],
                'diff.change_type' => ['sometimes', 'string', 'max:64'],
                'diff.resource' => ['sometimes', Rule::in(['education', 'experiences', 'contacts', 'certificates'])],
                'diff.record_id' => ['sometimes', 'integer', 'min:1'],
                'diff.changes' => ['sometimes', 'array'],
            ],
            default => throw new NotFoundOrForbiddenException,
        });
    }
}
