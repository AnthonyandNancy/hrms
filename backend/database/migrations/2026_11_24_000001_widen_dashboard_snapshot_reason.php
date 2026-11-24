<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `dashboard_snapshots.reason` was created as VARCHAR(120), but the
 * unavailable-metric explanations are full sentences and exceed that width.
 *
 * SQLite does not enforce VARCHAR length, so this only surfaced when the
 * dashboard ran against MySQL: the snapshot insert failed with
 * "Data too long for column 'reason'" and the dashboard returned 500.
 *
 * Forward-only: the original migration is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_snapshots', function (Blueprint $table): void {
            $table->text('reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_snapshots', function (Blueprint $table): void {
            $table->string('reason', 120)->nullable()->change();
        });
    }
};
