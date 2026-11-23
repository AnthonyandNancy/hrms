<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Database\Factories\AuthorityBindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $principal_id
 * @property string $capability_code
 * @property string $anchor_type
 * @property int|null $anchor_id
 * @property string $field_profile
 * @property int $auth_version
 * @property \Carbon\CarbonImmutable $valid_from
 * @property \Carbon\CarbonImmutable $valid_to
 * @property \Carbon\CarbonImmutable|null $last_used_at
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class AuthorityBinding extends Model
{
    /** @use HasFactory<AuthorityBindingFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'authority_bindings';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'principal_id',
        'capability_code',
        'anchor_type',
        'anchor_id',
        'anchor_key',
        'field_profile',
        'purpose_code',
        'valid_from',
        'valid_to',
        'source_type',
        'source_id',
        'auth_version',
        'last_used_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'principal_id' => 'integer',
            'anchor_id' => 'integer',
            'anchor_key' => 'integer',
            'source_id' => 'integer',
            'auth_version' => 'integer',
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): AuthorityBindingFactory
    {
        return AuthorityBindingFactory::new();
    }
}
