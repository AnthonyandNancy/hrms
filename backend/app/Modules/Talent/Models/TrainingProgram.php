<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingProgram extends Model
{
    protected $table = 'training_programs';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'code', 'title', 'status', 'default_capacity', 'recorded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'default_capacity' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }
}
