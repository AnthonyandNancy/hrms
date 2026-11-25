<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Illuminate\Database\Eloquent\Model;

final class DataScopePolicy extends Model
{
    protected $table = 'iam_data_scope_policies';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'template_code', 'label', 'anchor_type', 'active', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
