<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable publication ledger for performance results.
 *
 * A snapshot exists only for cycles whose review set was frozen while the
 * cycle was PUBLISHED. Draft and active ratings are never copied here: reading
 * an unfinished cycle remains a live projection and is explicitly marked
 * pending by the Talent result reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('performance_result_snapshots')) {
            Schema::create('performance_result_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('cycle_id')->index();
                $table->unsignedBigInteger('review_id')->index();
                $table->unsignedBigInteger('employment_id')->index();
                $table->string('self_rating', 16)->nullable();
                $table->string('manager_rating', 16)->nullable();
                $table->string('calibrated_rating', 16)->nullable();
                $table->string('final_rating', 16);
                $table->dateTime('published_at', 6);
                $table->unsignedBigInteger('published_by');
                $table->timestamps(6);

                $table->unique(['cycle_id', 'review_id'], 'performance_result_snapshot_unique');
            });
        }

        // Publication evidence also belongs on the cycle so a stable read can
        // identify who froze it without deriving an actor from audit text.
        if (! Schema::hasColumn('performance_cycles', 'published_at')) {
            Schema::table('performance_cycles', static function (Blueprint $table): void {
                $table->dateTime('published_at', 6)->nullable()->after('status');
            });
        }

        if (! Schema::hasColumn('performance_cycles', 'published_by')) {
            Schema::table('performance_cycles', static function (Blueprint $table): void {
                $table->unsignedBigInteger('published_by')->nullable()->after('published_at');
            });
        }

        $this->backfillPublishedCycles();
    }

    public function down(): void
    {
        // Forward-only: publication evidence must not be destroyed by a
        // routine schema rollback. Restore it from the pre-migration snapshot.
    }

    private function backfillPublishedCycles(): void
    {
        $cycles = DB::table('performance_cycles')
            ->where('status', 'PUBLISHED')
            ->orderBy('id')
            ->get(['id', 'updated_at', 'recorded_at', 'published_at', 'published_by']);

        foreach ($cycles as $cycle) {
            $cycleId = (int) $cycle->id;
            // Never overwrite an already recorded publication instant/actor:
            // a rerun must be idempotent and must not invent a new date.
            $publishedAt = $cycle->published_at !== null
                ? (string) $cycle->published_at
                : $this->timestamp($cycle->updated_at ?? $cycle->recorded_at ?? null);
            $publishedBy = $cycle->published_by !== null
                ? (int) $cycle->published_by
                : $this->publisherFor($cycleId);

            DB::table('performance_cycles')
                ->where('id', $cycleId)
                ->update([
                    'published_at' => $publishedAt,
                    'published_by' => $publishedBy,
                ]);

            $reviews = DB::table('performance_reviews')
                ->where('cycle_id', $cycleId)
                ->orderBy('id')
                ->get([
                    'id',
                    'employment_id',
                    'self_rating',
                    'manager_rating',
                    'calibrated_rating',
                ]);

            foreach ($reviews as $review) {
                $exists = DB::table('performance_result_snapshots')
                    ->where('cycle_id', $cycleId)
                    ->where('review_id', (int) $review->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $finalRating = $review->calibrated_rating
                    ?? $review->manager_rating
                    ?? $review->self_rating
                    ?? '0';

                DB::table('performance_result_snapshots')->insert([
                    'cycle_id' => $cycleId,
                    'review_id' => (int) $review->id,
                    'employment_id' => (int) $review->employment_id,
                    'self_rating' => $review->self_rating,
                    'manager_rating' => $review->manager_rating,
                    'calibrated_rating' => $review->calibrated_rating,
                    'final_rating' => (string) $finalRating,
                    'published_at' => $publishedAt,
                    'published_by' => $publishedBy,
                    'created_at' => $publishedAt,
                    'updated_at' => $publishedAt,
                ]);
            }
        }
    }

    private function publisherFor(int $cycleId): int
    {
        if (! Schema::hasTable('audit_events')) {
            return 0;
        }

        $publisher = DB::table('audit_events')
            ->where('action', 'talent.performance_cycle.published')
            ->where('aggregate_type', 'performance_cycle')
            ->where('aggregate_id', (string) $cycleId)
            ->whereNotNull('actor_user_id')
            ->orderByDesc('id')
            ->value('actor_user_id');

        return $publisher !== null ? (int) $publisher : 0;
    }

    private function timestamp(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return now('UTC')->format('Y-m-d H:i:s.u');
    }
};
