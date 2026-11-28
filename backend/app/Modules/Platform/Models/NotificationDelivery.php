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
        'notification_type',
        'payload_json',
        'status',
        'attempts',
        'claim_token',
        'claimed_at',
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
            'payload_json' => 'array',
            'claimed_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
