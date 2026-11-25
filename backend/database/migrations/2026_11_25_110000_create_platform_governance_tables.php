<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K11 platform governance tables.
 *
 * Owning module: Platform. Menu overrides are presentation-only; notification
 * dispatch is an outbox + delivery log with a per-tenant event key unique
 * constraint so a consumed event can never fan out twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_overrides', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('page_code', 120);
            $table->string('label', 160)->nullable();
            $table->boolean('visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'page_code'], 'menu_override_page_unique');
        });

        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 64);
            $table->string('channel', 24)->default('IN_APP');
            $table->string('subject', 200);
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'notification_template_code_unique');
        });

        Schema::create('notification_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('event_key', 120);
            $table->string('template_code', 64);
            $table->unsignedBigInteger('recipient_user_id');
            $table->json('payload_json');
            $table->string('status', 16)->default('PENDING')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'event_key'], 'notification_outbox_event_unique');
            $table->index(['tenant_id', 'status'], 'notification_outbox_status_idx');
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('notification_outbox_id')->unique();
            $table->string('template_code', 64);
            $table->unsignedBigInteger('recipient_user_id');
            $table->string('channel', 24);
            $table->string('status', 16)->default('PENDING')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'notification_delivery_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_outbox');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('menu_overrides');
    }
};
