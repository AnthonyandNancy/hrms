<?php

declare(strict_types=1);

namespace Tests\Unit\Projections;

use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use InvalidArgumentException;
use Tests\TestCase;

final class ProjectionResponderTest extends TestCase
{
    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

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
            'sourceHash' => self::HASH,
            'partial' => false,
            'partialReason' => null,
            'drilldown' => null,
        ];

        return new ProjectionResult(...array_replace($defaults, $overrides));
    }

    public function test_projection_envelope_serializes_every_contract_value(): void
    {
        $response = (new ProjectionResponder)->ok($this->projection([
            'drilldown' => ['resource' => 'employees', 'filters' => ['org_unit_id' => 7]],
        ]))->getData(true);

        $this->assertSame('OK', $response['code']);
        $this->assertSame([['id' => 1]], $response['data']['items']);
        $this->assertSame(1, $response['data']['page']);
        $this->assertSame(20, $response['data']['page_size']);
        $this->assertSame(1, $response['data']['total']);
        $this->assertSame('2026-11-25', $response['data']['as_of']);
        $this->assertSame('LIVE', $response['data']['freshness']);
        $this->assertSame(self::HASH, $response['data']['source_hash']);
        $this->assertFalse($response['data']['partial']);
        $this->assertNull($response['data']['partial_reason']);
        $this->assertSame(['resource' => 'employees', 'filters' => ['org_unit_id' => 7]], $response['data']['drilldown']);
    }

    public function test_a_stale_projection_must_explain_itself(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('partial_reason');

        $this->projection(['freshness' => 'STALE', 'partial' => true]);
    }

    public function test_a_stale_projection_must_be_partial(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('partial=true');

        $this->projection(['freshness' => 'STALE', 'partialReason' => 'rebuild failed']);
    }

    public function test_a_stale_projection_may_have_no_source_hash(): void
    {
        $result = $this->projection([
            'freshness' => 'STALE',
            'sourceHash' => '',
            'partial' => true,
            'partialReason' => 'projection rebuild failed',
        ]);

        $response = (new ProjectionResponder)->ok($result)->getData(true);

        $this->assertSame('STALE', $response['data']['freshness']);
        $this->assertTrue($response['data']['partial']);
        $this->assertSame('projection rebuild failed', $response['data']['partial_reason']);
        $this->assertSame('', $response['data']['source_hash']);
    }

    public function test_partial_requires_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('partial_reason');

        $this->projection(['partial' => true]);
    }

    public function test_freshness_is_limited_to_the_three_contract_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->projection(['freshness' => 'FRESH']);
    }

    public function test_a_materialized_projection_requires_a_sha256_source_hash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source_hash');

        $this->projection(['freshness' => 'MATERIALIZED', 'sourceHash' => 'not-a-hash']);
    }

    public function test_a_materialized_projection_accepts_a_prefixed_hash(): void
    {
        $result = $this->projection(['freshness' => 'MATERIALIZED', 'sourceHash' => 'sha256:'.self::HASH]);

        $this->assertSame('sha256:'.self::HASH, $result->sourceHash);
    }

    public function test_an_aggregate_total_may_be_negative_or_smaller_than_the_item_count(): void
    {
        $result = $this->projection([
            'items' => [['metric' => 'leave_liability'], ['metric' => 'leave_liability']],
            'total' => -960,
        ]);

        $this->assertSame(-960, $result->total);
    }

    public function test_as_of_must_be_a_real_calendar_date(): void
    {
        foreach (['2026-02-29', '2026-13-01', '2026-11-31'] as $date) {
            try {
                $this->projection(['asOf' => $date]);
                $this->fail("Impossible date {$date} was accepted.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('calendar date', $exception->getMessage());
            }
        }
    }

    public function test_a_real_leap_day_is_accepted(): void
    {
        $this->assertSame('2024-02-29', $this->projection(['asOf' => '2024-02-29'])->asOf);
    }

    public function test_page_and_page_size_must_stay_within_bounds(): void
    {
        foreach ([['page' => 0], ['page' => -1], ['pageSize' => 0], ['pageSize' => 101]] as $override) {
            try {
                $this->projection($override);
                $this->fail('Invalid pagination was accepted: '.json_encode($override, JSON_THROW_ON_ERROR));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_report_extensions_are_merged_without_touching_contract_fields(): void
    {
        $response = (new ProjectionResponder)->ok(
            $this->projection(['freshness' => 'MATERIALIZED']),
            ['columns' => [['key' => 'headcount', 'label' => 'Headcount']], 'chart' => null],
        )->getData(true);

        $this->assertSame([['key' => 'headcount', 'label' => 'Headcount']], $response['data']['columns']);
        $this->assertArrayHasKey('chart', $response['data']);
        $this->assertSame([['id' => 1]], $response['data']['items']);
        $this->assertSame(1, $response['data']['total']);
        $this->assertSame('MATERIALIZED', $response['data']['freshness']);
    }

    public function test_report_extensions_cannot_override_contract_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot override items');

        (new ProjectionResponder)->ok($this->projection(), ['items' => []]);
    }
}
