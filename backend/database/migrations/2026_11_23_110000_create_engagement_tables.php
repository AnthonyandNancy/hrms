<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('title', 200);
            $table->text('body');
            $table->json('audience_json');
            $table->timestamp('publish_at')->index();
            $table->timestamp('retract_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->string('status', 16)->default('DRAFT')->index();
            $table->boolean('requires_ack')->default(false);
            $table->unsignedInteger('audience_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'publish_at'], 'notices_tenant_status_publish_idx');
            $table->index(['tenant_id', 'created_by'], 'notices_tenant_creator_idx');
        });

        Schema::create('notice_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('notice_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('acknowledged_at');
            $table->timestamps();

            $table->unique(['notice_id', 'user_id'], 'notice_ack_unique');
            $table->index(['tenant_id', 'user_id', 'notice_id'], 'notice_ack_user_idx');
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('type', 64);
            $table->json('payload_json');
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'created_at'], 'notifications_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notice_acknowledgements');
        Schema::dropIfExists('notices');
    }
};
