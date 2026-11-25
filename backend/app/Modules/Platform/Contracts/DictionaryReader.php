<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

/**
 * Read port for tenant dictionaries. Business modules depend on this contract
 * so Platform owns the storage and can materialize or cache it later without
 * touching consumers.
 */
interface DictionaryReader
{
    /**
     * @return list<array{code: string, label: string, sort_order: int}>
     */
    public function entries(int $tenantId, string $type): array;
}
