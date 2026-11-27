<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable recipient set frozen at notice publication.
 *
 * Reads/reminders/exports must use this snapshot, never a live directory
 * re-resolution: otherwise a new employee would become a recipient of an
 * already-published notice.
 */
final class NoticeAudienceSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'notice_audience_snapshots';

    /** @var list<string> */
    protected $fillable = ['notice_id', 'user_id', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'notice_id' => 'integer',
            'user_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
