<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Tests\Support\PeopleTestCase;

final class TimelineTest extends PeopleTestCase
{
    public function test_timeline_orders_employment_assignment_terms_and_document_facts(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'TL-ORG');
        $employment = $scenario->employment($scenario->person(), $entity, 'TL-1');
        $scenario->assignment($employment, $org, ['valid_from' => '2026-02-01']);
        $scenario->terms($employment, 1, ['effective_from' => '2026-03-01']);
        $scenario->document($employment, $scenario->fileObject(), ['signed_at' => '2026-04-01 00:00:00']);

        $response = $this->getJson("/v1/employments/{$employment->id}/timeline")->assertOk();

        $events = array_column($response->json('data.items'), 'event');
        $this->assertSame(['EMPLOYMENT_CREATED', 'ASSIGNMENT_STARTED', 'TERMS_VERSION', 'DOCUMENT_ATTACHED'], $events);
        $dates = array_column($response->json('data.items'), 'effective_date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
    }

    public function test_timeline_filters_by_from_and_to_half_open(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'TL-2');

        $response = $this->getJson("/v1/employments/{$employment->id}/timeline?from=2026-01-01&to=2026-02-01")
            ->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('EMPLOYMENT_CREATED', $response->json('data.items.0.event'));

        $empty = $this->getJson("/v1/employments/{$employment->id}/timeline?from=2026-01-02&to=2026-02-01")
            ->assertOk();
        $this->assertSame(0, $empty->json('data.total'));
    }

    public function test_timeline_rejects_invalid_dates_and_hides_other_tenants(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'TL-3');

        $this->getJson("/v1/employments/{$employment->id}/timeline?from=2026-02-30")->assertUnprocessable();

        $this->actAs(tenantId: 2, userId: 6);
        $this->getJson("/v1/employments/{$employment->id}/timeline")->assertNotFound();
    }
}
