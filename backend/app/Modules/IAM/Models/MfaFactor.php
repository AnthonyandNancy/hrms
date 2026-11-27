<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string|null $secret_ciphertext
 * @property string|null $public_key
 * @property string|null $credential_id
 * @property string $label
 */
final class MfaFactor extends Model
{
    public const TYPE_TOTP = 'TOTP';

    public const TYPE_WEBAUTHN = 'WEBAUTHN';

    protected $table = 'mfa_factors';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'secret_ciphertext',
        'credential_id',
        'public_key',
        'label',
        'confirmed_at',
        'disabled_at',
    ];

    /** @var list<string> */
    protected $hidden = ['secret_ciphertext', 'public_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }
}
