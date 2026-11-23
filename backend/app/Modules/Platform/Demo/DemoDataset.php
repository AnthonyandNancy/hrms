<?php

declare(strict_types=1);

namespace App\Modules\Platform\Demo;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $dataset
 * @property string $status
 * @property string $mode
 * @property array<string, int|string> $populated_json
 * @property array<string, string> $deferred_json
 */
final class DemoDataset extends Model
{
    protected $table = 'demo_datasets';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'dataset',
        'status',
        'mode',
        'populated_json',
        'deferred_json',
        'fixture_hash',
        'seeded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'populated_json' => 'array',
            'deferred_json' => 'array',
            'seeded_at' => 'immutable_datetime',
        ];
    }
}
