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
            $table->bigInteger('base_salary_cents')->nullable()->after('salary_scheme_code');
            $table->string('salary_currency', 3)->nullable()->after('base_salary_cents');
        });
    }

    public function down(): void
    {
        Schema::table('employment_terms', function (Blueprint $table): void {
            $table->dropColumn(['base_salary_cents', 'salary_currency']);
        });
    }
};
