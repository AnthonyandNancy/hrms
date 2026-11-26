<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

final class NotificationTemplate extends Model
{
    public const CHANNELS = ['IN_APP', 'EMAIL', 'WECHAT_WORK'];

    protected $table = 'notification_templates';

    /** @var list<string> */
    protected $fillable = ['code', 'channel', 'subject', 'body', 'version', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'active' => 'boolean',
        ];
    }
}
