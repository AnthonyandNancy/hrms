<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only result fact captured by performance publication.
 *
 * The snapshot is the read model after publication. It deliberately carries
 * only rating strings and IDs; it never stores assessment prose or employee
 * profile data. Eloquent guards make accidental rewrite/delete a hard failure
 * in addition to the absence of any update/delete HTTP operation.
 */
/**
 * @property int|null $score_scaled
 * @property string|null $grade
 * @property string|null $source_revision
 * @property \Carbon\CarbonImmutable|null $published_at
 */
final class PerformanceResultSnapshot extends Model
{
    protected $table = 'performance_result_snapshots';

    /** @var list<string> */
    protected $fillable = [
        'cycle_id',
        'review_id',
        'employment_id',
        'self_rating',
        'manager_rating',
        'calibrated_rating',
        'final_rating',
        'score_scaled',
        'grade',
        'source_revision',
        'published_at',
        'published_by',
    ];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \DomainException('Performance result snapshots are append-only.');
        });

        self::deleting(function (): never {
            throw new \DomainException('Performance result snapshots are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cycle_id' => 'integer',
            'review_id' => 'integer',
            'employment_id' => 'integer',
            'published_at' => 'immutable_datetime',
            'published_by' => 'integer',
            'score_scaled' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
