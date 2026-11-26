<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('authority_bindings')) {
            return;
        }

        DB::table('authority_bindings')
            ->where('anchor_type', 'LEGAL_ENTITY')
            ->update([
                'anchor_type' => 'COMPANY',
                'anchor_id' => null,
                'anchor_key' => 0,
            ]);
    }

    public function down(): void
    {
        // Forward-only: COMPANY anchors are the single-company target shape
        // and cannot be losslessly converted back to LEGAL_ENTITY anchors.
    }
};
