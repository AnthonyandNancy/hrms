<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T16: business-event idempotency for in-app notification fan-out.
 *
 * Existing rows predate the key and stay nullable. The unique pair lets the
 * same business event replay without creating a second inbox row or provider
 * send; a genuinely different event gets a different key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('event_key', 191)->nullable()->after('user_id');
            $table->unique(['event_key', 'user_id'], 'notification_event_user_unique');
        });
    }

    public function down(): void
    {
        // Forward-only.
    }
};
