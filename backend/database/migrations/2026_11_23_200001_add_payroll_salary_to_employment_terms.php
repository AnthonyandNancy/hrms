<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Payroll consumes employment salary through the People terms contract.
 *
 * Salary columns (`base_salary_minor`, `salary_currency`) and the as-of index
 * are owned by People's base migration
 * (`2026_11_23_000011_create_people_tables.php`). This migration is therefore
 * intentionally a documented no-op: it preserves the payroll merge history
 * without duplicating People-owned schema in a second writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No payroll-owned columns on employment_terms.
    }

    public function down(): void
    {
        // Nothing to reverse.
    }
};
