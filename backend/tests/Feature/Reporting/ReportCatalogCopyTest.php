<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\Reporting\Services\ReportDefinitionCatalog;

/**
 * Business copy and domain grouping are server-owned. Human-readable labels
 * must be Chinese, while projection names stay internal.
 */
final class ReportCatalogCopyTest extends ReportingTestCase
{
    public function test_report_catalog_is_localized_and_domain_grouped(): void
    {
        $catalog = app(ReportDefinitionCatalog::class);
        $catalog->syncSystemDefinitions();

        foreach ($catalog->allActive() as $definition) {
            $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', (string) $definition['name']);
            $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', (string) $definition['description']);
            $this->assertContains((string) $definition['domain'], ['PEOPLE', 'ATTENDANCE', 'PAYROLL', 'RECRUITMENT']);
            $this->assertNotSame('', (string) $definition['domain']);

            foreach ((array) $definition['filters'] as $filter) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', (string) $filter['label']);
            }

            foreach ((array) $definition['fields'] as $field) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', (string) $field['label']);
            }
        }
    }

    public function test_payroll_money_labels_use_yuan_and_never_expose_minor_units(): void
    {
        $payroll = app(ReportDefinitionCatalog::class)->find('PAYROLL_COST');

        $this->assertNotNull($payroll);
        $this->assertSame('PAYROLL', $payroll['domain']);
        $labels = array_column($payroll['fields'], 'label');
        $this->assertContains('应发工资（元）', $labels);
        $this->assertContains('实发工资（元）', $labels);
        $this->assertContains('雇主成本（元）', $labels);
        $this->assertNotContains('Gross (minor units)', $labels);
        $this->assertNotContains('Net (minor units)', $labels);
        $this->assertNotContains('Employer cost (minor units)', $labels);
        $this->assertArrayNotHasKey('source_projection', $payroll);

        $money = array_values(array_filter(
            $payroll['fields'],
            static fn (array $field): bool => str_ends_with((string) $field['key'], '_cents'),
        ));
        $this->assertNotEmpty($money);

        foreach ($money as $field) {
            $this->assertSame('P2_SENSITIVE', $field['profile']);
            $this->assertTrue($field['exportable']);
        }
    }

    public function test_definitions_endpoint_returns_localized_business_dto_without_internal_projection(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $response = $this->getJson('/v1/reporting/definitions?page=1&page_size=100')->assertOk();
        $items = (array) $response->json('data.items');
        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertArrayNotHasKey('sourceProjection', (array) $item);
            $this->assertArrayNotHasKey('source_projection', (array) $item);
            $this->assertContains((string) $item['domain'], ['PEOPLE', 'ATTENDANCE', 'PAYROLL', 'RECRUITMENT']);
            $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', (string) $item['name']);
        }

        $payroll = array_values(array_filter(
            $items,
            static fn (array $item): bool => (string) $item['code'] === 'PAYROLL_COST',
        ));
        $this->assertCount(1, $payroll);
        $this->assertSame('PAYROLL', $payroll[0]['domain']);
        $this->assertContains('应发工资（元）', array_column($payroll[0]['fields'], 'label'));
    }

    public function test_definitions_mark_private_fields_non_exportable_without_an_export_grant(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['narrow_user']->id,
            (int) $ids['employment_a2_id'],
            (int) $ids['narrow_user']->auth_version,
        );

        \App\Modules\IAM\Models\AuthorityBinding::query()
            ->where('principal_id', (int) $ids['narrow_user']->id)
            ->where('capability_code', 'reporting.export')
            ->delete();
        \Illuminate\Support\Facades\Cache::flush();

        $items = (array) $this->getJson('/v1/reporting/definitions?page=1&page_size=100')
            ->assertOk()
            ->json('data.items');

        foreach ($items as $item) {
            foreach ((array) $item['fields'] as $field) {
                $this->assertFalse((bool) $field['exportable'], 'Without reporting.export no field may claim exportability.');
            }
        }
    }
}
