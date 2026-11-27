<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_unit_headcount_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('org_unit_id')->index();
            $table->unsignedInteger('headcount_limit');
            $table->date('effective_from');
            $table->dateTime('recorded_at', 6);
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['org_unit_id', 'effective_from'],
                'org_unit_headcount_versions_unit_effective_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_unit_headcount_versions');
    }
};
