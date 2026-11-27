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
        Schema::create('candidate_applications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('candidate_id')->index();
            $table->unsignedBigInteger('requisition_id')->index();
            $table->unsignedBigInteger('opening_id')->nullable()->index();
            $table->string('stage', 24)->default('NEW');
            $table->string('status', 24)->default('ACTIVE');
            $table->string('source', 64)->nullable();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['candidate_id', 'requisition_id'], 'candidate_application_unique');
            $table->index(['requisition_id', 'stage'], 'candidate_application_stage_idx');
        });

        $seen = [];

        $remember = static function (int $candidateId, int $requisitionId, string $stage, mixed $recordedAt, string $source) use (&$seen): void {
            $key = $candidateId.'|'.$requisitionId;

            if (isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $timestamp = $recordedAt !== null && (string) $recordedAt !== '' ? (string) $recordedAt : now('UTC')->format('Y-m-d H:i:s.u');

            DB::table('candidate_applications')->insertOrIgnore([
                'candidate_id' => $candidateId,
                'requisition_id' => $requisitionId,
                'opening_id' => null,
                'stage' => $stage,
                'status' => 'ACTIVE',
                'source' => $source,
                'recorded_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        };

        foreach (DB::table('interviews')->orderBy('id')->get() as $interview) {
            $remember((int) $interview->candidate_id, (int) $interview->requisition_id, 'INTERVIEW', $interview->recorded_at, 'LEGACY_INTERVIEW');
        }

        foreach (DB::table('offers')->orderBy('id')->get() as $offer) {
            $stage = (string) $offer->status === 'ACCEPTED' ? 'OFFER_ACCEPTED' : 'OFFER';
            $remember((int) $offer->candidate_id, (int) $offer->requisition_id, $stage, $offer->recorded_at, 'LEGACY_OFFER');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_applications');
    }
};
