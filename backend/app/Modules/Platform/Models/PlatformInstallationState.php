<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single authoritative installation marker for this company instance.
 *
 * @property int $id
 * @property string $instance_key
 * @property string $status
 * @property string $admin_username
 * @property int|null $admin_user_id
 * @property string|null $application_version
 * @property string|null $catalog_hash
 * @property string|null $last_error
 * @property \Carbon\CarbonImmutable|null $started_at
 * @property \Carbon\CarbonImmutable|null $completed_at
 */
final class PlatformInstallationState extends Model
{
    public const INSTANCE_KEY = 'primary';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_COMPLETED = 'COMPLETED';

    protected $table = 'platform_installation_states';

    /** @var list<string> */
    protected $fillable = [
        'instance_key',
        'status',
        'admin_username',
        'admin_user_id',
        'application_version',
        'catalog_hash',
        'last_error',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'admin_user_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
