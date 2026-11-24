<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            // Set while a password has been accepted but the second factor has
            // not. A session row in this state must never authorise a request.
            $table->string('mfa_challenge_hash', 64)->nullable()->after('csrf_hash');
            $table->timestamp('mfa_challenge_expires_at')->nullable()->after('mfa_challenge_hash');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropColumn(['mfa_challenge_hash', 'mfa_challenge_expires_at']);
        });
    }
};
