<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Illuminate\Database\Eloquent\Model;

final class FieldPolicy extends Model
{
    protected $table = 'iam_field_policies';

    /** @var list<string> */
    protected $fillable = ['field_key', 'field_profile'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
}
