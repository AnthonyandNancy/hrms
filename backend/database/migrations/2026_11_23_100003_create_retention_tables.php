<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_holds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('retention_class', 128);
            $table->string('subject_type', 64);
            $table->string('subject_id', 64);
            $table->string('reason', 500);
            $table->string('case_reference', 128)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'retention_class', 'subject_type', 'subject_id'], 'legal_holds_lookup_idx');
            $table->index(['tenant_id', 'released_at', 'ends_at'], 'legal_holds_active_idx');
        });

        Schema::create('retention_purge_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('retention_class', 128);
            $table->string('mode', 16);
            $table->string('status', 24);
            $table->unsignedInteger('candidates')->default(0);
            $table->unsignedInteger('held')->default(0);
            $table->unsignedInteger('purged')->default(0);
            $table->json('report_json')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_purge_runs');
        Schema::dropIfExists('legal_holds');
    }
};
