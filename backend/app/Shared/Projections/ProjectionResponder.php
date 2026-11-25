<?php

declare(strict_types=1);

namespace App\Shared\Projections;

use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Serializes every projection with the same envelope. Report endpoints may add
 * presentation fields (columns, chart) through $extra, but can never replace or
 * omit the contract fields.
 */
final class ProjectionResponder
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function ok(ProjectionResult $result, array $extra = []): JsonResponse
    {
        foreach (['items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash', 'partial', 'partial_reason', 'drilldown'] as $reserved) {
            if (array_key_exists($reserved, $extra)) {
                throw new \InvalidArgumentException("Projection extra fields cannot override {$reserved}.");
            }
        }

        return ApiResponse::ok([
            ...$extra,
            'items' => $result->items,
            'page' => $result->page,
            'page_size' => $result->pageSize,
            'total' => $result->total,
            'as_of' => $result->asOf,
            'freshness' => $result->freshness,
            'source_hash' => $result->sourceHash,
            'partial' => $result->partial,
            'partial_reason' => $result->partialReason,
            'drilldown' => $result->drilldown,
        ]);
    }
}
