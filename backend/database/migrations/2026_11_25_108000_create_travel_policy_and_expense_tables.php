<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->string('city_tier', 8);
            $table->unsignedBigInteger('hotel_cap_cents');
            $table->unsignedBigInteger('transport_cap_cents')->default(0);
            $table->date('effective_from');
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('PUBLISHED');
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at', 6)->nullable();

            // Publishing appends a version; it never rewrites an older row.
            $table->unique(['tenant_id', 'legal_entity_id', 'city_tier', 'version'], 'travel_policies_version_unique');
            $table->unique(['tenant_id', 'legal_entity_id', 'city_tier', 'effective_from'], 'travel_policies_effective_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'city_tier', 'effective_from'], 'travel_policies_asof_idx');
        });

        Schema::table('business_trip_records', function (Blueprint $table): void {
            // Approval queues and expense scope are legal-entity scoped. The
            // column is nullable because the pre-K6 legacy table had no legal
            // entity; every K6 write always populates it.
            $table->unsignedBigInteger('legal_entity_id')->nullable()->index();
        });

        Schema::create('travel_expenses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedBigInteger('travel_id');
            $table->string('category', 32);
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('receipt_file_id')->nullable();
            $table->string('status', 16)->default('CLAIMED');
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at', 6)->nullable();

            $table->index(['tenant_id', 'travel_id'], 'travel_expenses_travel_idx');
            $table->index(['tenant_id', 'employment_id', 'created_at'], 'travel_expenses_employment_idx');
            $table->index(['tenant_id', 'legal_entity_id', 'created_at'], 'travel_expenses_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_expenses');
        Schema::dropIfExists('travel_policies');
        Schema::table('business_trip_records', function (Blueprint $table): void {
            $table->dropColumn('legal_entity_id');
        });
    }
};
