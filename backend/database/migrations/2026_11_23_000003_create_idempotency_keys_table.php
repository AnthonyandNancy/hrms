<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('principal_id');
            $table->string('scope', 128);
            $table->string('key', 128);
            $table->string('request_hash', 64);
            $table->string('status', 16);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body_json')->nullable();
            $table->string('resource_type', 64)->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'principal_id', 'scope', 'key'], 'idempotency_scope_key_unique');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
