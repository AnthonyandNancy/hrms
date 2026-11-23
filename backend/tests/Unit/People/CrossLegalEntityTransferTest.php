<?php

declare(strict_types=1);

namespace Tests\Unit\People;

use App\Modules\Organization\Models\LegalEntity;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\Person;
use App\Modules\People\Services\EmploymentService;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CrossLegalEntityTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_contract_ends_old_and_opens_new_employment_for_same_person(): void
    {
        $this->bindClock();
        [$person, $old, $newEntity] = $this->fixtures();

        $new = app(EmploymentService::class)->transferLegalEntity(
            tenantId: 1,
            employmentId: (int) $old->id,
            input: ['legal_entity_id' => (int) $newEntity->id, 'effective_from' => '2026-06-01'],
            actorUserId: null,
        );

        $old->refresh();
        $this->assertSame('2026-06-01', $old->valid_to);
        $this->assertSame('TERMINATED', $old->status);
        $this->assertNotNull($old->superseded_at);
        $this->assertSame((int) $person->id, (int) $new->person_id);
        $this->assertSame((int) $newEntity->id, (int) $new->legal_entity_id);
        $this->assertSame('2026-06-01', $new->valid_from);
    }

    public function test_transfer_to_same_entity_is_rejected(): void
    {
        $this->bindClock();
        [, $old, , $oldEntity] = $this->fixtures();

        $this->expectException(ValidationException::class);

        app(EmploymentService::class)->transferLegalEntity(
            tenantId: 1,
            employmentId: (int) $old->id,
            input: ['legal_entity_id' => (int) $oldEntity->id, 'effective_from' => '2026-06-01'],
            actorUserId: null,
        );
    }

    public function test_transfer_before_current_start_is_rejected(): void
    {
        $this->bindClock();
        [, $old, $newEntity] = $this->fixtures();

        $this->expectException(ValidationException::class);

        app(EmploymentService::class)->transferLegalEntity(
            tenantId: 1,
            employmentId: (int) $old->id,
            input: ['legal_entity_id' => (int) $newEntity->id, 'effective_from' => '2025-12-01'],
            actorUserId: null,
        );
    }

    public function test_unknown_employment_is_404_domain_exception(): void
    {
        $this->bindClock();
        $this->fixtures();

        $this->expectException(\App\Shared\Exceptions\NotFoundOrForbiddenException::class);

        app(EmploymentService::class)->transferLegalEntity(
            tenantId: 1,
            employmentId: 999999,
            input: ['legal_entity_id' => 2, 'effective_from' => '2026-06-01'],
            actorUserId: null,
        );
    }

    /**
     * @return array{0: Person, 1: Employment, 2: LegalEntity, 3: LegalEntity}
     */
    private function fixtures(): array
    {
        $oldEntity = LegalEntity::query()->create([
            'tenant_id' => 1,
            'code' => 'T-CN',
            'name' => 'CN',
            'country_code' => 'CN',
            'timezone' => 'Asia/Shanghai',
            'currency_code' => 'CNY',
            'status' => 'ACTIVE',
        ]);
        $newEntity = LegalEntity::query()->create([
            'tenant_id' => 1,
            'code' => 'T-SG',
            'name' => 'SG',
            'country_code' => 'SG',
            'timezone' => 'Asia/Singapore',
            'currency_code' => 'SGD',
            'status' => 'ACTIVE',
        ]);
        $person = Person::query()->create([
            'tenant_id' => 1,
            'legal_name' => 'Transfer Person',
            'id_type' => null,
            'id_number_ciphertext' => null,
            'id_number_hash' => null,
            'birth_date' => '1990-01-01',
            'gender' => null,
            'nationality' => 'CN',
            'mobile_ciphertext' => null,
            'email' => null,
        ]);
        $employment = Employment::query()->create([
            'tenant_id' => 1,
            'person_id' => (int) $person->id,
            'legal_entity_id' => (int) $oldEntity->id,
            'employee_no' => 'TRANSFER-1',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'hire_date' => '2026-01-01',
            'termination_date' => null,
            'seniority_start_date' => '2026-01-01',
            'recorded_at' => now('UTC'),
            'source_type' => 'TEST',
        ]);

        return [$person, $employment, $newEntity, $oldEntity];
    }

    private function bindClock(): void
    {
        $this->app->instance(\App\Shared\Time\Clock::class, new FrozenClock('2026-11-23T01:00:00Z'));
    }
}
