<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_scheme_items', function (Blueprint $table): void {
            $table->json('formula_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_scheme_items', function (Blueprint $table): void {
            $table->dropColumn('formula_json');
        });
    }
};
