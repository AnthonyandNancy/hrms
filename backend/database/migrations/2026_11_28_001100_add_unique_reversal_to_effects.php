<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One original effect can have at most one reversal row. NULL entries (normal
 * forward effects) are not constrained. This closes the concurrent-reversal
 * race while keeping the append-only reversal evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('effects', function (Blueprint $table): void {
            $table->unique('reversal_of_id', 'effects_reversal_of_unique');
        });
    }

    public function down(): void
    {
        Schema::table('effects', function (Blueprint $table): void {
            $table->dropUnique('effects_reversal_of_unique');
        });
    }
};
