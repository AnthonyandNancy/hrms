<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('username', 80);
            $table->string('email')->nullable();
            $table->string('password_hash')->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->unsignedBigInteger('auth_version')->default(1);
            $table->unsignedBigInteger('person_id')->nullable()->index();
            $table->timestamp('mfa_enrolled_at')->nullable();
            $table->boolean('is_privileged')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'username'], 'users_tenant_username_unique');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
    }
};
