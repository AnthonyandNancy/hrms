<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_units', function (Blueprint $table): void {
            $table->unsignedBigInteger('manager_employment_id')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('org_units', function (Blueprint $table): void {
            $table->dropIndex(['manager_employment_id']);
            $table->dropColumn('manager_employment_id');
        });
    }
};
