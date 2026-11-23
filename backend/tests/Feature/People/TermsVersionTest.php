<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\EmploymentTerms;
use Tests\Support\PeopleTestCase;

final class TermsVersionTest extends PeopleTestCase
{
    public function test_versions_increment_and_history_is_preserved(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $person = $scenario->person();
        $employment = $scenario->employment($person, $entity, 'TV-1');

        $this->postJson("/v1/employments/{$employment->id}/terms", [
            'effective_from' => '2026-01-01',
            'work_location' => 'Shanghai',
            'work_schedule' => 'STANDARD',
        ])->assertCreated()->assertJsonPath('data.version', 1);

        $this->postJson("/v1/employments/{$employment->id}/terms", [
            'effective_from' => '2026-06-01',
            'work_location' => 'Singapore',
            'work_schedule' => 'STANDARD',
        ])->assertCreated()->assertJsonPath('data.version', 2);

        $first = EmploymentTerms::query()->where('version', 1)->firstOrFail();
        $this->assertSame('Shanghai', $first->work_location);
        $this->assertSame('2026-06-01', $first->effective_to);
    }

    public function test_historical_terms_are_returned_as_of_the_requested_date(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $person = $scenario->person();
        $employment = $scenario->employment($person, $entity, 'TV-2');
        $org = $scenario->orgUnit($entity, 'TV-ORG');
        $scenario->assignment($employment, $org);

        $this->postJson("/v1/employments/{$employment->id}/terms", [
            'effective_from' => '2026-01-01',
            'work_location' => 'Shanghai',
            'work_schedule' => 'STANDARD',
        ])->assertCreated();
        $this->postJson("/v1/employments/{$employment->id}/terms", [
            'effective_from' => '2026-06-01',
            'work_location' => 'Singapore',
            'work_schedule' => 'STANDARD',
        ])->assertCreated();

        $this->getJson("/v1/employees/{$employment->id}?as_of=2026-05-31")
            ->assertOk()
            ->assertJsonPath('data.terms.version', 1)
            ->assertJsonPath('data.terms.work_location', 'Shanghai');
        $this->getJson("/v1/employees/{$employment->id}?as_of=2026-06-01")
            ->assertOk()
            ->assertJsonPath('data.terms.version', 2)
            ->assertJsonPath('data.terms.work_location', 'Singapore');
    }

    public function test_terms_are_not_edited_in_place(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $person = $scenario->person();
        $employment = $scenario->employment($person, $entity, 'TV-3');

        $this->postJson("/v1/employments/{$employment->id}/terms", [
            'effective_from' => '2026-01-01',
            'work_location' => 'Shanghai',
            'work_schedule' => 'STANDARD',
        ])->assertCreated();

        $first = EmploymentTerms::query()->firstOrFail();
        $first->work_location = 'Tampered';

        try {
            $first->save();
            $this->fail('Historical terms must not be editable in place.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->postJson("/v1/employments/{$employment->id}/terms", [
            'effective_from' => '2026-02-01',
            'work_location' => 'Singapore',
            'work_schedule' => 'STANDARD',
        ])->assertCreated();

        $this->assertSame(2, EmploymentTerms::query()->count());
        $this->assertSame('Shanghai', EmploymentTerms::query()->where('version', 1)->value('work_location'));
    }

    public function test_non_monotonic_effective_date_is_a_conflict(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $person = $scenario->person();
        $employment = $scenario->employment($person, $entity, 'TV-4');
        $scenario->terms($employment, 1, ['effective_from' => '2026-06-01']);

        $this->postJson("/v1/employments/{$employment->id}/terms", [
            'effective_from' => '2026-06-01',
            'work_location' => 'Shanghai',
            'work_schedule' => 'STANDARD',
        ])->assertConflict()->assertJsonPath('data.reason', 'TERMS_EFFECTIVE_DATE_NOT_MONOTONIC');
    }
}
