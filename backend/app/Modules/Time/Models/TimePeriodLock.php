<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable one-row-per-month mutex. The row has no business meaning; it exists
 * so every Time write and the month close/reopen contend on the same locked
 * record instead of racing an `exists()` check that has already been released.
 *
 * @property int $id
 * @property string $month
 */
final class TimePeriodLock extends Model
{
    protected $table = 'time_period_locks';

    /** @var list<string> */
    protected $fillable = [
        'month',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
