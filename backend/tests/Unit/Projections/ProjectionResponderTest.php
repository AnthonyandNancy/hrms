<?php

declare(strict_types=1);

namespace Tests\Unit\Projections;

use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use Tests\TestCase;

final class ProjectionResponderTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function projection(array $overrides = []): ProjectionResult
    {
        $defaults = [
            'items' => [['id' => 1]],
            'page' => 1,
            'pageSize' => 20,
            'total' => 1,
            'asOf' => '2026-11-25',
            'freshness' => 'LIVE',
            'sourceHash' => str_repeat('a', 64),
            'partial' => false,
            'partialReason' => null,
            'drilldown' => null,
        ];

        return new ProjectionResult(...array_replace($defaults, $overrides));
    }

    public function test_projection_envelope_has_contract_fields(): void
    {
        $response = (new ProjectionResponder)->ok($this->projection())->getData(true);

        $this->assertSame('OK', $response['code']);
        foreach ([
            'items', 'page', 'page_size', 'total', 'as_of', 'freshness',
            'source_hash', 'partial', 'partial_reason', 'drilldown',
        ] as $key) {
            $this->assertArrayHasKey($key, $response['data']);
        }
        $this->assertSame('LIVE', $response['data']['freshness']);
        $this->assertSame(64, strlen($response['data']['source_hash']));
    }

    public function test_a_stale_projection_must_explain_itself(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->projection(['freshness' => 'STALE']);
    }

    public function test_partial_requires_a_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->projection(['partial' => true]);
    }

    public function test_freshness_is_limited_to_the_three_contract_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->projection(['freshness' => 'FRESH']);
    }

    public function test_a_materialized_projection_requires_a_sha256_source_hash(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->projection(['freshness' => 'MATERIALIZED', 'sourceHash' => 'not-a-hash']);
    }

    public function test_page_and_page_size_must_stay_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->projection(['page' => 0]);
    }

    public function test_extra_report_fields_are_only_merged_into_data(): void
    {
        $response = (new ProjectionResponder)->ok(
            $this->projection(['freshness' => 'MATERIALIZED']),
            ['columns' => [['key' => 'headcount', 'label' => 'Headcount']], 'chart' => null],
        )->getData(true);

        $this->assertArrayHasKey('columns', $response['data']);
        $this->assertArrayHasKey('chart', $response['data']);
        $this->assertArrayNotHasKey('items', $response['data']['columns']);
    }
}
