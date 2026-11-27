<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $expression = match ($driver) {
            'sqlite' => "CAST(json_extract(intent_json, '$.offer_id') AS INTEGER)",
            'mysql', 'mariadb' => "CAST(JSON_UNQUOTE(JSON_EXTRACT(intent_json, '$.offer_id')) AS UNSIGNED)",
            default => null,
        };

        Schema::table('lifecycle_intents', function (Blueprint $table): void {
            $table->unsignedBigInteger('offer_id')->nullable()->index();
        });

        if ($expression !== null) {
            DB::statement('UPDATE lifecycle_intents SET offer_id = '.$expression.' WHERE offer_id IS NULL');
        }

        DB::statement('CREATE UNIQUE INDEX lifecycle_intent_offer_unique ON lifecycle_intents (case_type, offer_id)');
    }

    public function down(): void
    {
        if (Schema::hasIndex('lifecycle_intents', 'lifecycle_intent_offer_unique')) {
            Schema::table('lifecycle_intents', function (Blueprint $table): void {
                $table->dropUnique('lifecycle_intent_offer_unique');
            });
        }

        if (Schema::hasColumn('lifecycle_intents', 'offer_id')) {
            Schema::table('lifecycle_intents', function (Blueprint $table): void {
                $table->dropColumn('offer_id');
            });
        }
    }
};
