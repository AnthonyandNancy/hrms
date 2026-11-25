<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Labour-contract ledger. A renewal creates a new row and links the prior
 * contract; history is never overwritten or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_contracts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->string('contract_no', 80);
            $table->string('contract_type', 32);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('signed_on')->nullable();
            $table->string('status', 24)->default('ACTIVE');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('previous_contract_id')->nullable()->index();
            $table->unsignedBigInteger('renewed_by_id')->nullable()->index();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('legal_entity_id')->nullable();
            $table->text('terms_summary')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->string('source_type', 32)->default('API');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'contract_no'], 'employment_contracts_tenant_no_unique');
            $table->index(['tenant_id', 'employment_id', 'status'], 'employment_contracts_status_idx');
            $table->index(['tenant_id', 'end_date'], 'employment_contracts_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_contracts');
    }
};
