<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_capabilities', function (Blueprint $table): void {
            $table->unsignedBigInteger('anchor_id')->nullable()->after('anchor_type');
        });
    }

    public function down(): void
    {
        Schema::table('role_capabilities', function (Blueprint $table): void {
            $table->dropColumn('anchor_id');
        });
    }
};
