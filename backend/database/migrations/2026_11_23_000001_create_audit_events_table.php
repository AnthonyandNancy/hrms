<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_type', 32)->default('USER');
            $table->string('action', 128);
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 64);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->timestamp('occurred_at')->index();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
