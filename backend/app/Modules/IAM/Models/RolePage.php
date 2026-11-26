<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $role_code
 * @property string $page_code
 */
final class RolePage extends Model
{
    protected $table = 'role_pages';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['role_code', 'page_code', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
