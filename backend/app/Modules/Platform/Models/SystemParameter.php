<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Typed runtime parameter. JSON storage keeps the reader generic while the
 * contract exposes a typed default so callers never branch on missing values.
 */
final class SystemParameter extends Model
{
    protected $table = 'sys_parameters';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'key', 'value_json', 'scope'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'value_json' => 'array',
        ];
    }
}
