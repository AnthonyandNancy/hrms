<?php

declare(strict_types=1);

namespace App\Modules\IAM\Contracts;

/**
 * Read/export port over the append-only IAM security-event table.
 *
 * IAM owns `auth_events`; Platform renders the audit screen through this
 * contract rather than importing IAM models.
 */
interface SecurityAuditReader
{
    /**
     * @param  array{actor?: string|null, action?: string|null, result?: string|null, from?: string|null, to?: string|null, page?: int, page_size?: int}  $filters
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int}
     */
    public function page(array $filters): array;

    /**
     * @param  list<string>  $fields
     * @param  array{actor?: string|null, action?: string|null, result?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array{fields: list<string>, rows: list<array<string, mixed>>}
     */
    public function export(array $fields, array $filters): array;

    /**
     * @return list<string>
     */
    public function exportFields(): array;
}
