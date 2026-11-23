<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Services\TenantBusinessDate;
use App\Modules\People\Models\Document;
use App\Modules\People\Models\Employment;
use App\Modules\People\Queries\AsOfEmployeeQuery;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmployeeController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly TenantBusinessDate $businessDates,
    ) {}

    public function index(Request $request, AsOfEmployeeQuery $query): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'org_unit_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $asOf = $this->businessDates->resolve($data['as_of'] ?? null, $principal->tenantId());

        $result = $query->paginate(
            $principal,
            $this->authorizer,
            'people.employee.read',
            $asOf->value(),
            ['org_unit_id' => $data['org_unit_id'] ?? null, 'q' => $data['q'] ?? null],
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 20),
        );

        return ApiResponse::ok($result);
    }

    public function show(Request $request, int $employmentId, AsOfEmployeeQuery $query): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate(['as_of' => ['nullable', new BusinessDateRule]]);
        $asOf = $this->businessDates->resolve($data['as_of'] ?? null, $principal->tenantId());
        $this->assertAllows($principal, 'people.employee.read', AnchorRef::global(), $asOf->value());

        $asOfValue = $asOf->value();
        $employment = Employment::query()
            ->where('tenant_id', $principal->tenantId())
            ->whereKey($employmentId)
            ->where('valid_from', '<=', $asOfValue)
            ->where('valid_to', '>', $asOfValue)
            ->first();

        if ($employment === null) {
            throw new NotFoundOrForbiddenException;
        }

        $visible = $this->authorizer
            ->constrain(
                Employment::query()
                    ->where('tenant_id', $principal->tenantId())
                    ->whereKey($employmentId)
                    ->where('valid_from', '<=', $asOfValue)
                    ->where('valid_to', '>', $asOfValue),
                $principal,
                'people.employee.read',
                $asOfValue,
            )
            ->exists();

        if (! $visible) {
            throw new NotFoundOrForbiddenException;
        }

        $projection = $query->detail($employment, $asOf->value());
        $contracts = [];
        $documents = Document::query()
            ->where('tenant_id', $principal->tenantId())
            ->where('owner_type', 'EMPLOYMENT')
            ->where('owner_id', $employmentId)
            ->where('category', 'CONTRACT')
            ->where(function ($builder) use ($asOf): void {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>', $asOf->value());
            })
            ->orderByDesc('version')
            ->get();

        foreach ($documents as $document) {
            $contracts[] = [
                'id' => (int) $document->id,
                'version' => (int) $document->version,
                'signed_at' => $document->getAttribute('signed_at') !== null
                    ? \Carbon\CarbonImmutable::parse((string) $document->getAttribute('signed_at'))->toIso8601ZuluString()
                    : null,
                'expires_at' => $document->getAttribute('expires_at') !== null ? (string) $document->getAttribute('expires_at') : null,
                'retention_class' => (string) $document->retention_class,
            ];
        }

        $projection['active_contracts'] = $contracts;

        return ApiResponse::ok($projection);
    }

    public function timeline(Request $request, int $employmentId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'from' => ['nullable', new BusinessDateRule],
            'to' => ['nullable', new BusinessDateRule],
        ]);
        $this->businessDates->resolve($data['from'] ?? null, $principal->tenantId(), 'from');
        $this->businessDates->resolve($data['to'] ?? null, $principal->tenantId(), 'to');
        $this->assertAllows($principal, 'people.employee.read', AnchorRef::global(), $this->businessDates->today($principal->tenantId())->value());

        $employment = Employment::query()->where('tenant_id', $principal->tenantId())->whereKey($employmentId)->first();

        if ($employment === null) {
            throw new NotFoundOrForbiddenException;
        }

        $from = $data['from'] ?? '0001-01-01';
        $to = $data['to'] ?? '9999-12-31';
        $events = [];

        $events[] = $this->event('EMPLOYMENT', (int) $employment->id, 'EMPLOYMENT_CREATED', (string) $employment->valid_from, ['status' => (string) $employment->status]);

        foreach ($employment->newQuery()->getConnection()->table('assignments')->where('tenant_id', $principal->tenantId())->where('employment_id', $employmentId)->orderBy('valid_from')->get() as $assignment) {
            $events[] = $this->event('ASSIGNMENT', (int) $assignment->id, 'ASSIGNMENT_STARTED', (string) $assignment->valid_from, ['org_unit_id' => (int) $assignment->org_unit_id, 'assignment_type' => (string) $assignment->assignment_type]);
        }

        foreach ($employment->newQuery()->getConnection()->table('employment_terms')->where('tenant_id', $principal->tenantId())->where('employment_id', $employmentId)->orderBy('version')->get() as $terms) {
            $events[] = $this->event('TERMS', (int) $terms->id, 'TERMS_VERSION', (string) $terms->effective_from, ['version' => (int) $terms->version]);
        }

        foreach ($employment->newQuery()->getConnection()->table('documents')->where('tenant_id', $principal->tenantId())->where('owner_type', 'EMPLOYMENT')->where('owner_id', $employmentId)->orderBy('id')->get() as $document) {
            $events[] = $this->event('DOCUMENT', (int) $document->id, 'DOCUMENT_ATTACHED', (string) ($document->signed_at ?? $document->created_at), ['category' => (string) $document->category]);
        }

        $events = array_values(array_filter($events, static fn (array $event): bool => $event['effective_date'] >= $from && $event['effective_date'] < $to));
        usort($events, static fn (array $a, array $b): int => [$a['effective_date'], $a['type'], $a['id']] <=> [$b['effective_date'], $b['type'], $b['id']]);

        return ApiResponse::ok(ApiResponse::list($events, 1, max(1, count($events)), count($events)));
    }

    private function assertAllows(Principal $principal, string $capability, AnchorRef $anchor, string $on): void
    {
        if (! $this->authorizer->allows($principal, $capability, $anchor, $on)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{type: string, id: int, event: string, effective_date: string, metadata: array<string, mixed>}
     */
    private function event(string $type, int $id, string $event, string $date, array $metadata): array
    {
        return ['type' => $type, 'id' => $id, 'event' => $event, 'effective_date' => $date, 'metadata' => $metadata];
    }
}
