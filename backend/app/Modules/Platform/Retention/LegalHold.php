<?php

declare(strict_types=1);

namespace App\Modules\Platform\Retention;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $retention_class
 * @property string $subject_type
 * @property string $subject_id
 */
final class LegalHold extends Model
{
    protected $table = 'legal_holds';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'retention_class',
        'subject_type',
        'subject_id',
        'reason',
        'case_reference',
        'starts_at',
        'ends_at',
        'released_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }
}
