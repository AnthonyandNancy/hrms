<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

final class NotificationDelivery extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_SENT = 'SENT';

    public const STATUS_FAILED = 'FAILED';

    protected $table = 'notification_deliveries';

    /** @var list<string> */
    protected $fillable = [
        'notification_outbox_id',
        'notification_id',
        'template_code',
        'recipient_user_id',
        'channel',
        'status',
        'attempts',
        'provider_message_id',
        'last_error',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notification_outbox_id' => 'integer',
            'notification_id' => 'integer',
            'recipient_user_id' => 'integer',
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
