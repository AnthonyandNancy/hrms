<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Emergency/family contact. phone_ciphertext is encrypted and hidden.
 */
final class EmployeeContact extends Model
{
    protected $table = 'employee_contacts';

    /** @var list<string> */
    protected $fillable = [
        'employment_id',
        'name',
        'relationship',
        'phone_ciphertext',
        'email',
        'is_emergency',
        'is_primary',
        'recorded_at',
        'superseded_at',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    protected $hidden = ['phone_ciphertext'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['phone_ciphertext' => 'encrypted', 'is_emergency' => 'boolean', 'is_primary' => 'boolean'];
    }
}
