<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything IAM persists. Semantic authorization rules live in code; the
 * tables below are read models, credentials and audit evidence only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->string('csrf_hash', 64);
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('mfa_verified')->default(false);
            $table->timestamp('mfa_verified_until')->nullable();
            $table->unsignedBigInteger('auth_version');
            $table->timestamp('idle_expires_at')->index();
            $table->timestamp('absolute_expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamp('last_seen_at');
            $table->timestamp('created_at');
        });

        Schema::create('mfa_factors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('type', 16);
            $table->text('secret_ciphertext')->nullable();
            $table->string('credential_id', 255)->nullable()->index();
            $table->text('public_key')->nullable();
            $table->string('label', 80);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('disabled_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'credential_id'], 'mfa_factor_credential_unique');
        });

        Schema::create('recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('code_hash', 64);
            $table->timestamp('used_at')->nullable()->index();
            $table->timestamp('created_at');

            $table->unique(['user_id', 'code_hash'], 'recovery_code_unique');
        });

        Schema::create('capabilities', function (Blueprint $table): void {
            $table->string('code', 120)->primary();
            $table->string('resource', 64);
            $table->json('actions_json');
            $table->json('anchor_types_json');
            $table->string('field_profile_max', 24);
            $table->string('risk', 16);
            $table->string('sod_group', 48)->nullable();
            $table->string('description', 255);
            $table->timestamp('synced_at');
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->string('code', 64)->primary();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name', 120);
            $table->boolean('is_template')->default(false);
            $table->unsignedBigInteger('owner')->nullable();
            $table->string('purpose', 255);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('role_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('role_code', 64);
            $table->string('capability_code', 120);
            $table->string('anchor_type', 24)->default('SELF');
            $table->string('field_profile', 24)->default('P0_PUBLIC');
            $table->timestamps();

            $table->unique(['role_code', 'capability_code', 'anchor_type'], 'role_capability_anchor_unique');
            $table->index(['tenant_id', 'role_code']);
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('role_code', 64)->index();
            $table->timestamp('valid_from')->index();
            $table->timestamp('valid_to')->index();
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->string('reason', 255);
            $table->unsignedBigInteger('auth_version')->default(1);
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'user_id', 'valid_from', 'valid_to'], 'user_roles_effective_idx');
        });

        Schema::create('authority_bindings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('principal_id')->index();
            $table->string('capability_code', 120);
            $table->string('anchor_type', 24);
            $table->unsignedBigInteger('anchor_id')->nullable();
            // Null anchors are normalised to 0 so the uniqueness invariant is
            // deterministic on both MySQL and SQLite.
            $table->unsignedBigInteger('anchor_key')->default(0);
            $table->string('field_profile', 24);
            $table->string('purpose_code', 48);
            $table->timestamp('valid_from')->index();
            $table->timestamp('valid_to')->index();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('auth_version');
            $table->timestamp('last_used_at')->nullable()->index();
            $table->timestamp('created_at');

            $table->unique(
                ['principal_id', 'capability_code', 'anchor_type', 'anchor_key', 'valid_from'],
                'authority_binding_effective_unique',
            );
            $table->index(['tenant_id', 'principal_id', 'capability_code', 'valid_from'], 'authority_binding_lookup_idx');
            $table->index(['tenant_id', 'capability_code', 'anchor_type', 'anchor_id'], 'authority_binding_anchor_idx');
        });

        Schema::create('auth_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('event_type', 64)->index();
            $table->string('result', 16);
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamp('occurred_at')->index();

            $table->index(['tenant_id', 'user_id', 'occurred_at'], 'auth_events_user_time_idx');
        });

        Schema::create('break_glass_grants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('requester_user_id')->index();
            $table->unsignedBigInteger('approver_user_id')->index();
            $table->string('reason', 500);
            $table->timestamp('granted_at')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->json('scope_json');
            $table->timestamp('alert_sent_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'requester_user_id', 'expires_at'], 'break_glass_requester_idx');
        });

        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('username', 80)->index();
            $table->string('ip', 45)->nullable()->index();
            $table->boolean('succeeded')->default(false);
            $table->timestamp('attempted_at')->index();

            $table->index(['username', 'ip', 'attempted_at'], 'login_attempt_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('break_glass_grants');
        Schema::dropIfExists('auth_events');
        Schema::dropIfExists('authority_bindings');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_capabilities');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('capabilities');
        Schema::dropIfExists('recovery_codes');
        Schema::dropIfExists('mfa_factors');
        Schema::dropIfExists('sessions');
    }
};
