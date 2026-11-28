<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People-owned encrypted onboarding draft. Workflow Case/Intent payloads keep
 * only the draft id; PII never enters Workflow JSON or projections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people_onboarding_drafts', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_no', 64)->index();
            $table->string('idempotency_key', 128)->unique();
            $table->unsignedBigInteger('case_id')->nullable()->unique();
            $table->string('status', 24)->default('PENDING')->index();
            $table->longText('payload_ciphertext');
            $table->unsignedBigInteger('created_by');
            $table->dateTime('consumed_at', 6)->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people_onboarding_drafts');
    }
};
