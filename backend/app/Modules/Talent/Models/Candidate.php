<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class Candidate extends Model
{
    protected $table = 'candidates';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'display_name', 'email', 'mobile_ciphertext', 'email_hash', 'mobile_hash',
        'source', 'status', 'linked_person_id', 'recorded_at',
    ];

    /** @var list<string> */
    protected $hidden = ['email', 'mobile_ciphertext', 'email_hash', 'mobile_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'mobile_ciphertext' => 'encrypted',
            'linked_person_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
