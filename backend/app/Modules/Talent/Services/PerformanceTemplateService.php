<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Models\PerformanceTemplate;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Performance templates are versioned and may only be published when every
 * section weight sums to exactly 100.
 */
final class PerformanceTemplateService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function publish(int $tenantId, int $actorUserId, array $input): array
    {
        $sections = array_values(is_array($input['sections'] ?? null) ? $input['sections'] : []);
        $total = 0;

        foreach ($sections as $section) {
            if (! is_array($section) || ! isset($section['weight']) || ! is_numeric($section['weight'])) {
                throw new ValidationException(['sections' => ['Every section requires a numeric weight.']], 'The given data was invalid.', ['reason' => 'INVALID_WEIGHT_TOTAL']);
            }

            $total += (int) $section['weight'];
        }

        if ($sections === [] || $total !== 100) {
            throw new ValidationException(['sections' => ['Section weights must total exactly 100.']], 'The given data was invalid.', ['reason' => 'INVALID_WEIGHT_TOTAL']);
        }

        return DB::transaction(function () use ($tenantId, $actorUserId, $input, $sections): array {
            $version = ((int) PerformanceTemplate::query()
                ->where('tenant_id', $tenantId)
                ->where('code', (string) $input['code'])
                ->max('version')) + 1;

            /** @var PerformanceTemplate $template */
            $template = PerformanceTemplate::query()->create([
                'tenant_id' => $tenantId,
                'code' => (string) $input['code'],
                'name' => (string) $input['name'],
                'sections_json' => $sections,
                'version' => max(1, $version),
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record($tenantId, $actorUserId, 'talent.performance_template.published', 'performance_template', (int) $template->id, after: [
                'code' => (string) $template->code,
                'version' => (int) $template->version,
            ]);

            return $this->payload($template);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId): array
    {
        return PerformanceTemplate::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('code')
            ->orderByDesc('version')
            ->get()
            ->map(fn (PerformanceTemplate $template): array => $this->payload($template))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PerformanceTemplate $template): array
    {
        return [
            'template_id' => (int) $template->id,
            'code' => (string) $template->code,
            'name' => (string) $template->name,
            'sections' => $template->sections_json,
            'weight_total' => array_sum(array_map(static fn (array $section): int => (int) ($section['weight'] ?? 0), array_values((array) $template->sections_json))),
            'version' => (int) $template->version,
            'published_at' => $template->recorded_at?->toIso8601ZuluString(),
        ];
    }
}
