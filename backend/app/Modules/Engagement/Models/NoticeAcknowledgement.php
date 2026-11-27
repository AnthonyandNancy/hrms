<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $notice_id
 * @property int $user_id
 * @property \Carbon\CarbonImmutable $acknowledged_at
 */
final class NoticeAcknowledgement extends Model
{
    protected $table = 'notice_acknowledgements';

    /** @var list<string> */
    protected $fillable = [
        'notice_id',
        'user_id',
        'acknowledged_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notice_id' => 'integer',
            'user_id' => 'integer',
            'acknowledged_at' => 'immutable_datetime',
        ];
    }
}
