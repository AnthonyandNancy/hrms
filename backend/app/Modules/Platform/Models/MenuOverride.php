<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Presentation-only navigation override. It never changes page registration,
 * route paths or capability requirements.
 */
final class MenuOverride extends Model
{
    protected $table = 'menu_overrides';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'page_code', 'label', 'visible', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
