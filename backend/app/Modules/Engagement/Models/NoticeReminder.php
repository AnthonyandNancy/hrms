<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Delivery evidence for one notice reminder. A reminder never changes the
 * notice acknowledgement ledger; it only records that the requester asked the
 * notification service to remind one unread recipient.
 *
 * @property int $id
 * @property int $notice_id
 * @property int $user_id
 * @property int $requested_by
 * @property int|null $notification_id
 * @property \Carbon\CarbonImmutable $recorded_at
 */
final class NoticeReminder extends Model
{
    protected $table = 'notice_reminders';

    /** @var list<string> */
    protected $fillable = [
        'notice_id',
        'user_id',
        'requested_by',
        'notification_id',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notice_id' => 'integer',
            'user_id' => 'integer',
            'requested_by' => 'integer',
            'notification_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
