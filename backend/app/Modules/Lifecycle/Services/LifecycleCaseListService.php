<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use Carbon\CarbonImmutable;

/**
 * Read model for the lifecycle case list. Lifecycle owns intent facts;
 * workflow status comes through the gateway, never by reading workflow tables.
 */
final class LifecycleCaseListService
{
    public function __construct(private readonly WorkflowCaseGateway $workflow) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int}
     */
    public function paginate(int $tenantId, array $filters, int $page, int $pageSize): array
    {
        $query = LifecycleIntent::query()
            ->where('tenant_id', $tenantId)
            ->when(isset($filters['case_type']) && $filters['case_type'] !== '', fn ($builder) => $builder->where('case_type', (string) $filters['case_type']))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($builder) => $builder->where('status', (string) $filters['status']))
            ->when(isset($filters['employment_id']) && (int) $filters['employment_id'] > 0, fn ($builder) => $builder->where('employment_id', (int) $filters['employment_id']))
            ->when(isset($filters['q']) && $filters['q'] !== '', function ($builder) use ($filters): void {
                $builder->where(function ($inner) use ($filters): void {
                    $term = '%'.$filters['q'].'%';
                    $inner->where('case_type', 'like', $term)
                        ->orWhere('subject_type', 'like', $term);
                });
            })
            ->orderByDesc('id');

        $total = (int) (clone $query)->count();
        $items = [];

        foreach ($query->forPage($page, $pageSize)->get() as $intent) {
            $case = $this->workflow->caseForTenant($tenantId, (int) $intent->case_id);

            $items[] = [
                'case_id' => (int) $intent->case_id,
                'case_no' => $case?->caseNo,
                'case_type' => (string) $intent->case_type,
                'domain_status' => (string) $intent->status,
                'application_status' => $case?->applicationStatus,
                'effect_status' => $case?->effectStatus,
                'subject_type' => (string) $intent->subject_type,
                'subject_id' => $intent->subject_id !== null ? (int) $intent->subject_id : null,
                'employment_id' => $intent->employment_id !== null ? (int) $intent->employment_id : null,
                'result_employment_id' => $intent->result_employment_id !== null ? (int) $intent->result_employment_id : null,
                'effective_at' => $this->iso($intent->effective_at),
                'recorded_at' => $this->iso($intent->recorded_at),
            ];
        }

        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toIso8601ZuluString();
    }
}
