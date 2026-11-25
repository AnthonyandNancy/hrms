<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

final class NotificationOutbox extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_DISPATCHED = 'DISPATCHED';

    protected $table = 'notification_outbox';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'event_key', 'template_code', 'recipient_user_id', 'payload_json', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'recipient_user_id' => 'integer',
            'payload_json' => 'array',
        ];
    }
}
