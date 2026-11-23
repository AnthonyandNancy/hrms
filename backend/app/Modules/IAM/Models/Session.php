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
 * @property int $tenant_id
 * @property int $auth_version
 * @property bool $mfa_verified
 */
final class Session extends Model
{
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'tenant_id',
        'token_hash',
        'csrf_hash',
        'ip',
        'user_agent',
        'mfa_verified',
        'mfa_verified_until',
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
            'tenant_id' => 'integer',
            'auth_version' => 'integer',
            'mfa_verified' => 'boolean',
            'mfa_verified_until' => 'immutable_datetime',
            'idle_expires_at' => 'immutable_datetime',
            'absolute_expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected static function newFactory(): SessionFactory
    {
        return SessionFactory::new();
    }
}
