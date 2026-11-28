<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single production birth marker. A partial install remains PENDING so health
 * readiness stays red until the same administrator completes every step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_installation_states', function (Blueprint $table): void {
            $table->id();
            $table->string('instance_key', 32)->unique();
            $table->string('status', 24)->default('PENDING');
            $table->string('admin_username', 64);
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('application_version', 32)->nullable();
            $table->char('catalog_hash', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_installation_states');
    }
};
