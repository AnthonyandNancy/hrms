<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class CandidateCommunication extends Model
{
    protected $table = 'candidate_communications';

    /** @var list<string> */
    protected $fillable = ['candidate_id', 'channel', 'direction', 'subject', 'body', 'consent_basis', 'sent_at', 'recorded_at'];

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
            'sent_at' => 'immutable_datetime',
        ];
    }
}
