<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable per-month mutex shared by Time mutations, close and reopen.
 *
 * A closure row cannot be used as the mutex because it is only created when a
 * month is first closed; writes before that would have nothing to lock. This
 * row exists for every month that has ever seen a Time write or close and is
 * locked FOR UPDATE inside the same transaction as that write/close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_period_locks', function (Blueprint $table): void {
            $table->id();
            $table->string('month', 7)->unique();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_period_locks');
    }
};
