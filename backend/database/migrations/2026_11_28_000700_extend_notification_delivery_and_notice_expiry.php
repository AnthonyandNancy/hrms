<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T16: evidence-based notification delivery and notice link expiry.
 *
 * The Platform-owned notification_deliveries ledger already existed for the
 * template/outbox dispatcher. This forward-only migration extends it with the
 * Engagement notification identity and provider receipt instead of creating a
 * duplicate table (which would fork the delivery ledger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            // Nullable for legacy template/outbox deliveries, which are keyed
            // by notification_outbox_id instead.
            $table->unsignedBigInteger('notification_id')->nullable()->after('notification_outbox_id');
            $table->unsignedBigInteger('recipient_user_id')->change();
            $table->string('channel', 24)->change();
            $table->string('provider_message_id', 160)->nullable()->after('attempts');
            $table->index(['notification_id', 'recipient_user_id'], 'notification_delivery_notification_user_idx');
            $table->unique(['notification_id', 'recipient_user_id'], 'notification_delivery_notification_user_unique');
        });

        Schema::table('notices', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('retract_at')->index();
        });
    }

    public function down(): void
    {
        // Forward-only: no destructive rollback.
    }
};
