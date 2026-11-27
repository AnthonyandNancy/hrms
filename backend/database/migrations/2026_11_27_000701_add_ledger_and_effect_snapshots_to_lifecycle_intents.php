<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lifecycle_intents', function (Blueprint $table): void {
            $table->date('planned_effective_on')->nullable()->index();
            $table->string('deduplication_key', 64)->nullable()->index();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->json('effect_result_json')->nullable();
            $table->dateTime('detail_frozen_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lifecycle_intents', function (Blueprint $table): void {
            $table->dropColumn([
                'planned_effective_on',
                'deduplication_key',
                'before_json',
                'after_json',
                'effect_result_json',
                'detail_frozen_at',
            ]);
        });
    }
};
