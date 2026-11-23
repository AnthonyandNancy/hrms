<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only resource table for authorizer compile tests. It lives in the test
 * schema and mimics a People-owned projection; IAM never imports People models.
 */
final class EmployeeStub extends Model
{
    protected $table = 'employee_stubs';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'employment_id',
        'org_unit_id',
        'legal_entity_id',
        'case_id',
        'payroll_run_id',
        'name',
        'email',
        'id_number',
        'salary',
    ];
}

/** @extends Factory<EmployeeStub> */
final class EmployeeStubFactory extends Factory
{
    protected $model = EmployeeStub::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'employment_id' => fake()->numberBetween(1, 1000),
            'org_unit_id' => 1,
            'legal_entity_id' => 1,
            'case_id' => null,
            'payroll_run_id' => null,
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'id_number' => '110101199001011234',
            'salary' => '999999',
        ];
    }
}
