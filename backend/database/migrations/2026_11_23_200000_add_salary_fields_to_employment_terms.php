<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_terms', function (Blueprint $table): void {
            $table->unsignedBigInteger('base_salary_minor')->nullable()->after('salary_scheme_code');
            $table->char('salary_currency', 3)->default('CNY')->after('base_salary_minor');
        });
    }

    public function down(): void
    {
        Schema::table('employment_terms', function (Blueprint $table): void {
            $table->dropColumn(['base_salary_minor', 'salary_currency']);
        });
    }
};
