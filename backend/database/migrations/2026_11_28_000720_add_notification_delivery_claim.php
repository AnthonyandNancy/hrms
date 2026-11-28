<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T16a: delivery reservation and replay evidence.
 *
 * A PENDING row is committed before the provider call. `claim_token` makes the
 * single in-flight sender explicit, so duplicate/concurrent calls cannot both
 * send. `notification_type` and `payload_json` let the owning Engagement
 * contract replay the original delivery through its own channel instead of
 * guessing from a Platform outbox payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->string('notification_type', 64)->nullable()->after('channel');
            $table->json('payload_json')->nullable()->after('notification_type');
            $table->string('claim_token', 64)->nullable()->after('attempts')->index();
            $table->timestamp('claimed_at')->nullable()->after('claim_token')->index();
        });
    }

    public function down(): void
    {
        // Forward-only.
    }
};
