<?php

declare(strict_types=1);

namespace App\Modules\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $category
 */
final class Document extends Model
{
    public const CATEGORIES = ['CONTRACT', 'ID', 'EDUCATION', 'CERTIFICATE', 'OTHER'];

    public const UPDATED_AT = null;

    protected $table = 'documents';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'category',
        'file_object_id',
        'version',
        'signed_at',
        'sha256',
        'expires_at',
        'retention_class',
        'legal_hold',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'file_object_id',
        'sha256',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'file_object_id' => 'integer',
            'version' => 'integer',
            'legal_hold' => 'boolean',
            'signed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
