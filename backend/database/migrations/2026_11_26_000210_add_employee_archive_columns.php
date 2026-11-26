<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employments', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->string('archive_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employments', function (Blueprint $table): void {
            $table->dropColumn(['archived_at', 'archived_by', 'archive_reason']);
        });
    }
};
