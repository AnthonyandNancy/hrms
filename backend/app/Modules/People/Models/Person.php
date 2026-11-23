<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $legal_name
 * @property string|null $preferred_name
 */
final class Person extends Model
{
    protected $table = 'persons';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'legal_name',
        'preferred_name',
        'id_type',
        'id_number_ciphertext',
        'id_number_hash',
        'birth_date',
        'gender',
        'nationality',
        'mobile_ciphertext',
        'email',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'id_number_ciphertext',
        'id_number_hash',
        'mobile_ciphertext',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'id_number_ciphertext' => 'encrypted',
            'mobile_ciphertext' => 'encrypted',
            'birth_date' => 'date:Y-m-d',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
