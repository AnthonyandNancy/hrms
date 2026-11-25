<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notice governance: approval linkage, archive lifecycle, versioned templates.
 *
 * Owning module: Engagement. Existing notice rows remain valid: the new
 * approval/archive columns are nullable/defaulted and history is not rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', function (Blueprint $table): void {
            $table->unsignedBigInteger('approval_case_id')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->string('archive_previous_status', 32)->nullable();
            $table->string('archive_reason', 500)->nullable();
            $table->unsignedBigInteger('template_id')->nullable()->index();
            $table->unsignedBigInteger('template_version')->nullable();
            $table->string('template_code', 64)->nullable();
        });

        Schema::create('notice_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->unsignedInteger('version')->default(1);
            $table->text('body_template');
            $table->json('variables_json');
            $table->string('status', 16)->default('PUBLISHED');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->unique(['tenant_id', 'code', 'version'], 'notice_templates_tenant_code_version_unique');
            $table->index(['tenant_id', 'status'], 'notice_templates_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_templates');

        Schema::table('notices', function (Blueprint $table): void {
            $table->dropColumn([
                'approval_case_id',
                'approved_at',
                'approved_by',
                'archived_at',
                'archived_by',
                'archive_previous_status',
                'archive_reason',
                'template_id',
                'template_version',
                'template_code',
            ]);
        });
    }
};
