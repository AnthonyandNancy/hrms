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
        Schema::table('candidate_conversions', function (Blueprint $table): void {
            $table->unsignedBigInteger('onboarding_case_id')->nullable()->index();
        });

        foreach (DB::table('candidate_conversions')->orderBy('id')->get() as $conversion) {
            $caseId = DB::table('offers')
                ->where('id', (int) $conversion->offer_id)
                ->value('onboarding_case_id');

            if ($caseId !== null) {
                DB::table('candidate_conversions')
                    ->where('id', (int) $conversion->id)
                    ->update(['onboarding_case_id' => (int) $caseId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('candidate_conversions', function (Blueprint $table): void {
            $table->dropColumn('onboarding_case_id');
        });
    }
};
