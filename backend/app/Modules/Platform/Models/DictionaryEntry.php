<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Company-wide enumerations rendered by forms and validated against on write.
 * The `type` is a code-owned namespace; `code` is never free text.
 */
final class DictionaryEntry extends Model
{
    protected $table = 'sys_dictionaries';

    /** @var list<string> */
    protected $fillable = ['type', 'code', 'label', 'sort_order', 'active'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }
}
