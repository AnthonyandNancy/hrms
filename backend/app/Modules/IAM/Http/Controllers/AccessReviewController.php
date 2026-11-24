<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Shared\ApiResponse;
use App\Shared\Authorization\SodRules;
use App\Shared\Time\Clock;
use Illuminate\Http\JsonResponse;

/**
 * Read-only projection of the quarterly access review. It reuses the same
 * computation as `iam:access-review`; it never mutates a binding.
 */
final class AccessReviewController
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CapabilityCatalog $catalog,
        private readonly AuthenticationContext $context,
    ) {}

    public function show(): JsonResponse
    {
        $tenantId = $this->context->requirePrincipal()->tenantId();
        $now = $this->clock->now();
        $cutoff = $now->subDays(90);

        $highRiskCodes = array_values(array_map(
            static fn ($definition): string => $definition->code,
            array_filter(
                $this->catalog->all(),
                static fn ($definition): bool => in_array($definition->risk, ['high', 'critical'], true),
            ),
        ));

        $unused = AuthorityBinding::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('capability_code', $highRiskCodes)
            ->where('valid_from', '<=', $now)
            ->where('created_at', '<', $cutoff)
            ->where('valid_to', '>', $now)
            ->orderBy('id')
            ->get()
            ->map(static fn (AuthorityBinding $binding): array => [
                'binding_id' => (int) $binding->id,
                'principal_id' => (int) $binding->principal_id,
                'capability_code' => (string) $binding->capability_code,
                'risk' => $binding->capability_code === '*' ? 'critical' : $this->catalog->find((string) $binding->capability_code)?->risk,
                'created_at' => $binding->created_at?->toIso8601ZuluString(),
            ])
            ->all();

        $byPrincipal = [];
        foreach (AuthorityBinding::query()->where('tenant_id', $tenantId)->where('valid_from', '<=', $now)->where('valid_to', '>', $now)->get() as $binding) {
            $byPrincipal[(int) $binding->principal_id][] = $binding->capability_code;
        }

        $risks = [];
        foreach ($byPrincipal as $principalId => $codes) {
            foreach (SodRules::risks($codes) as $risk) {
                $risks[] = [
                    'principal_id' => $principalId,
                    'capabilities' => $risk['pair'],
                    'kind' => $risk['kind'],
                ];
            }
        }

        return ApiResponse::ok([
            'generated_at' => $now->toIso8601ZuluString(),
            'unused_high_risk_bindings' => $unused,
            'sod_risks' => $risks,
        ]);
    }
}
