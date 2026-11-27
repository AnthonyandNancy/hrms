<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Database\Factories\SessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Server-side session. Only SHA-256 hashes of the bearer token and CSRF token
 * are persisted; the raw values exist only in the issuing response.
 *
 * @property string $id
 * @property int $user_id
 * @property int $auth_version
 * @property bool $mfa_verified
 * @property \Carbon\CarbonImmutable|null $mfa_verified_until
 * @property string|null $mfa_challenge_hash
 * @property \Carbon\CarbonImmutable|null $mfa_challenge_expires_at
 * @property \Carbon\CarbonImmutable $idle_expires_at
 * @property \Carbon\CarbonImmutable $absolute_expires_at
 * @property \Carbon\CarbonImmutable|null $revoked_at
 * @property \Carbon\CarbonImmutable $last_seen_at
 * @property \Carbon\CarbonImmutable $created_at
 */
final class Session extends Model
{
    /** @use HasFactory<SessionFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'token_hash',
        'csrf_hash',
        'ip',
        'user_agent',
        'mfa_verified',
        'mfa_verified_until',
        'mfa_challenge_hash',
        'mfa_challenge_expires_at',
        'auth_version',
        'idle_expires_at',
        'absolute_expires_at',
        'revoked_at',
        'last_seen_at',
        'created_at',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash', 'csrf_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'auth_version' => 'integer',
            'mfa_verified' => 'boolean',
            'mfa_verified_until' => 'immutable_datetime',
            'mfa_challenge_expires_at' => 'immutable_datetime',
            'idle_expires_at' => 'immutable_datetime',
            'absolute_expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected static function newFactory(): SessionFactory
    {
        return SessionFactory::new();
    }
}
