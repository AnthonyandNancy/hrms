<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\DictionaryReader;
use App\Modules\Platform\Models\DictionaryEntry;

final class DatabaseDictionaryReader implements DictionaryReader
{
    /**
     * @return list<array{code: string, label: string, sort_order: int}>
     */
    public function entries(int $tenantId, string $type): array
    {
        return DictionaryEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('type', $type)
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(static fn (DictionaryEntry $entry): array => [
                'code' => (string) $entry->code,
                'label' => (string) $entry->label,
                'sort_order' => (int) $entry->sort_order,
            ])->all();
    }
}
