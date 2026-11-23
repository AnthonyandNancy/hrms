<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('event_type', 128)->index();
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 64);
            $table->unsignedBigInteger('aggregate_version');
            $table->json('payload_json');
            $table->timestamp('occurred_at');
            $table->timestamp('available_at')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'published_at', 'available_at'], 'outbox_pending_idx');
            $table->index(['aggregate_type', 'aggregate_id', 'aggregate_version'], 'outbox_aggregate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
