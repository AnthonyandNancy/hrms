<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('positions', 'status')) {
            Schema::table('positions', function (Blueprint $table): void {
                $table->string('status', 16)->default('ACTIVE')->after('title');
            });
        }

        Schema::create('position_qualifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('position_id')->index();
            $table->unsignedInteger('version');
            $table->json('requirements_json');
            $table->date('effective_from');
            $table->dateTime('recorded_at', 6);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(
                ['tenant_id', 'position_id', 'version'],
                'position_qualifications_tenant_position_version_unique',
            );
        });

        Schema::create('position_grades', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->index();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->unsignedSmallInteger('level');
            $table->boolean('active')->default(true);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['tenant_id', 'legal_entity_id', 'code'], 'position_grades_tenant_entity_code_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'active', 'level'], 'position_grades_tenant_entity_active_level_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_grades');
        Schema::dropIfExists('position_qualifications');

        if (Schema::hasColumn('positions', 'status')) {
            Schema::table('positions', function (Blueprint $table): void {
                $table->dropColumn('status');
            });
        }
    }
};
