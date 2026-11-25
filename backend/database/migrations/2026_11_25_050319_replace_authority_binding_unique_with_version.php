<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History-preserving authorization snapshots need the generation in the
 * uniqueness key. The previous key collapsed a replayed grant into the prior
 * generation, so only the row that triggered a change survived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authority_bindings', function (Blueprint $table): void {
            $table->dropUnique('authority_binding_effective_unique');
            $table->unique(
                ['principal_id', 'capability_code', 'anchor_type', 'anchor_key', 'valid_from', 'auth_version'],
                'authority_binding_version_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('authority_bindings', function (Blueprint $table): void {
            $table->dropUnique('authority_binding_version_unique');
            $table->unique(
                ['principal_id', 'capability_code', 'anchor_type', 'anchor_key', 'valid_from'],
                'authority_binding_effective_unique',
            );
        });
    }
};
