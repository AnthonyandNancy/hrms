<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class TalentPoolTag extends Model
{
    protected $table = 'talent_pool_tags';

    /** @var list<string> */
    protected $fillable = ['candidate_id', 'tag', 'reason', 'retention_until', 'status', 'recorded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'candidate_id' => 'integer',
        ];
    }
}
