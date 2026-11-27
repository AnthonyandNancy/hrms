<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Job-grade catalogue used by position and compensation bands.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $level
 * @property bool $active
 */
final class PositionGrade extends Model
{
    protected $table = 'position_grades';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'level',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
