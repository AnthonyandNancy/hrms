<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 32);
            $table->string('name', 128);
            $table->boolean('is_statutory')->default(false);
            $table->boolean('is_annual')->default(false);
            $table->string('unit', 8);
            $table->decimal('annual_entitlement_days', 6, 2)->nullable();
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('allow_negative')->default(false);
            $table->json('expiry_rule_json')->nullable();
            $table->string('legal_basis', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['tenant_id', 'code'], 'leave_type_code_unique');
        });

        Schema::create('leave_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->date('opened_on');
            $table->date('closed_on')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['tenant_id', 'employment_id', 'leave_type_id', 'opened_on'], 'leave_account_open_unique');
            $table->index(['tenant_id', 'employment_id', 'leave_type_id'], 'leave_account_lookup_idx');
        });

        Schema::create('leave_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->string('account', 16);
            $table->integer('quantity_minutes');
            $table->date('business_date');
            $table->date('effective_on');
            $table->string('source_type', 48);
            $table->string('source_id', 128);
            $table->unsignedBigInteger('case_id')->nullable()->index();
            $table->string('reason', 500);
            $table->char('entry_hash', 64);
            $table->timestamp('recorded_at', 6);
            $table->timestamp('created_at', 6)->nullable();

            $table->unique(
                ['tenant_id', 'employment_id', 'leave_type_id', 'source_type', 'source_id', 'account'],
                'leave_ledger_source_unique',
            );
            $table->index(
                ['tenant_id', 'employment_id', 'leave_type_id', 'business_date'],
                'leave_ledger_balance_idx',
            );
        });

        Schema::create('leave_balances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->date('as_of');
            $table->integer('granted')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('consumed')->default(0);
            $table->integer('released')->default(0);
            $table->integer('expired')->default(0);
            $table->integer('adjusted')->default(0);
            $table->integer('reversed')->default(0);
            $table->integer('balance')->default(0);
            $table->timestamp('updated_at', 6)->nullable();

            $table->unique(['tenant_id', 'employment_id', 'leave_type_id'], 'leave_balance_projection_unique');
        });

        Schema::create('leave_request_projections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedBigInteger('case_id');
            $table->date('from_date');
            $table->date('to_date');
            $table->integer('minutes');
            $table->string('state', 24);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['tenant_id', 'case_id'], 'leave_case_projection_unique');
            $table->index(['tenant_id', 'employment_id', 'from_date', 'to_date'], 'leave_conflict_idx');
        });

        Schema::create('overtime_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id');
            $table->date('business_date');
            $table->integer('minutes');
            $table->string('status', 16);
            $table->unsignedBigInteger('case_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'employment_id', 'business_date'], 'overtime_lookup_idx');
        });

        Schema::create('business_trip_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employment_id');
            $table->date('from_date');
            $table->date('to_date');
            $table->string('status', 16);
            $table->unsignedBigInteger('case_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'employment_id', 'from_date', 'to_date'], 'trip_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_trip_records');
        Schema::dropIfExists('overtime_records');
        Schema::dropIfExists('leave_request_projections');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_ledger_entries');
        Schema::dropIfExists('leave_accounts');
        Schema::dropIfExists('leave_types');
    }
};
