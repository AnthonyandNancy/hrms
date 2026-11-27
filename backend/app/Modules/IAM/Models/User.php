<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Access subject. Deliberately separate from a natural person: person_id is a
 * nullable cross-module reference and is never a foreign key owned by IAM.
 *
 * @property int $id
 * @property string $username
 * @property string|null $email
 * @property string|null $password_hash
 * @property string $status
 * @property int $auth_version
 * @property int|null $person_id
 * @property bool $is_privileged
 * @property \Carbon\CarbonImmutable|null $mfa_enrolled_at
 * @property \Carbon\CarbonImmutable|null $last_login_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_LOCKED = 'LOCKED';

    public const STATUS_DISABLED = 'DISABLED';

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = [
        'username',
        'email',
        'password_hash',
        'status',
        'auth_version',
        'person_id',
        'mfa_enrolled_at',
        'is_privileged',
        'last_login_at',
    ];

    /** @var list<string> */
    protected $hidden = ['password_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auth_version' => 'integer',
            'is_privileged' => 'boolean',
            'person_id' => 'integer',
            'mfa_enrolled_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Session, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasConfirmedMfa(): bool
    {
        return $this->mfa_enrolled_at !== null;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
