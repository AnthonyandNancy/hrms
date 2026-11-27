<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Authorization\DataScopeTemplateCatalog;
use App\Modules\IAM\Models\DataScopePolicy;
use App\Shared\Observability\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class DataScopePolicyService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return list<array{template: string, anchor_type: string, label: string, active: bool, sort_order: int, description: string}>
     */
    public function rows(): array
    {
        $stored = DataScopePolicy::query()
            ->get()
            ->keyBy('template_code');

        $rows = [];

        foreach (DataScopeTemplateCatalog::templates() as $template) {
            /** @var DataScopePolicy|null $policy */
            $policy = $stored->get($template['template']);

            $rows[] = [
                'template' => $template['template'],
                'anchor_type' => $template['anchor_type'],
                'label' => $policy?->label !== null ? (string) $policy->label : $template['label'],
                'active' => $policy?->active !== null ? (bool) $policy->active : true,
                'sort_order' => $policy?->sort_order !== null ? (int) $policy->sort_order : 0,
                'description' => $template['description'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array{template: string, anchor_type: string, label: string, active: bool, sort_order: int}  $data
     * @return array{template: string, anchor_type: string, label: string, active: bool, sort_order: int}
     */
    public function upsert(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var DataScopePolicy|null $existing */
            $existing = DataScopePolicy::query()
                ->where('template_code', $data['template'])
                ->first();

            $before = $existing === null ? [] : [
                'label' => (string) $existing->label,
                'anchor_type' => (string) $existing->anchor_type,
                'active' => (bool) $existing->active,
                'sort_order' => (int) $existing->sort_order,
            ];

            /** @var DataScopePolicy $policy */
            $policy = DataScopePolicy::query()->updateOrCreate(
                ['template_code' => $data['template']],
                [
                    'anchor_type' => $data['anchor_type'],
                    'label' => $data['label'],
                    'active' => $data['active'],
                    'sort_order' => $data['sort_order'],
                ],
            );

            $after = [
                'label' => (string) $policy->label,
                'anchor_type' => (string) $policy->anchor_type,
                'active' => (bool) $policy->active,
                'sort_order' => (int) $policy->sort_order,
            ];

            $this->audit->record(
                actorUserId: \App\Shared\RequestContext::userId(),
                action: 'iam.data_scope.updated',
                aggregateType: 'data_scope_template',
                aggregateId: (string) $policy->template_code,
                before: $before,
                after: $after,
                metadata: ['request_id' => \App\Shared\RequestContext::requestId()],
            );

            return ['template' => (string) $policy->template_code, ...$after];
        });
    }
}
