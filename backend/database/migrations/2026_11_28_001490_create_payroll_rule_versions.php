<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_rule_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scheme_item_id')->index();
            $table->unsignedInteger('version');
            $table->json('ast_json');
            $table->string('stage', 24)->default('EARNING');
            $table->json('dependencies_json')->nullable();
            $table->string('rounding_mode', 16)->default('HALF_UP');
            $table->string('unit', 16)->default('CENT');
            $table->string('engine_version', 64)->default('PAYROLL_RULE_V1');
            $table->char('hash', 64);
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('published_by')->nullable();
            $table->dateTime('published_at', 6)->nullable();
            $table->string('retire_reason', 500)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['scheme_item_id', 'version'], 'payroll_rule_version_unique');
            $table->index(['scheme_item_id', 'status', 'version'], 'payroll_rule_publish_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_rule_versions');
    }
};
