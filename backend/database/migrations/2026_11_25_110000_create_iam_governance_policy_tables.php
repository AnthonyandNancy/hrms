<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K11 governance policy projections.
 *
 * Owning module: IAM. Scope templates and sensitive fields are code-owned;
 * these rows only select one declared template/field and its presentation
 * metadata. No predicate, SQL or new field can be stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iam_data_scope_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('template_code', 48);
            $table->string('label', 120);
            $table->string('anchor_type', 24);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'template_code'], 'iam_scope_template_unique');
        });

        Schema::create('iam_field_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('field_key', 120);
            $table->string('field_profile', 24);
            $table->timestamps();

            $table->unique(['tenant_id', 'field_key'], 'iam_field_policy_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iam_field_policies');
        Schema::dropIfExists('iam_data_scope_policies');
    }
};
