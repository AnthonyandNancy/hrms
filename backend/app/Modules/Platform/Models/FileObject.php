<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Metadata for a private object-store file. Storage keys and hashes are never
 * exposed as public URLs; downloads must be authorized and signed separately.
 */
final class FileObject extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'file_objects';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'storage_key',
        'sha256',
        'original_name',
        'mime',
        'size_bytes',
        'owner_user_id',
        'purpose',
        'retention_class',
        'legal_hold',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'storage_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'legal_hold' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
