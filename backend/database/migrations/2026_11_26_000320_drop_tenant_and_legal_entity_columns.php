<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_COLUMNS = ['tenant_id', 'legal_entity_id'];

    public function up(): void
    {
        $tables = array_values(array_filter(
            array_map(
                static fn (mixed $table): string => (string) (is_array($table) ? $table['name'] : $table),
                Schema::getTables(),
            ),
            fn (string $table): bool => $this->hasLegacyColumn($table),
        ));

        /*
         * The replacement keys are derived from the live schema before any DDL.
         * The old key always starts with tenant_id/legal_entity_id; the remaining
         * columns form the single-company business key. No table map is kept.
         */
        $replacementKeys = [];
        foreach ($tables as $table) {
            $replacementKeys[$table] = $this->replacementUniqueKeys($table);
        }

        foreach ($tables as $table) {
            $this->dropDependentForeignKeys($table);
            $this->dropDependentIndexes($table);
        }

        foreach ($tables as $table) {
            $legacyColumns = array_values(array_filter(
                self::LEGACY_COLUMNS,
                static fn (string $column): bool => Schema::hasColumn($table, $column),
            ));

            if ($legacyColumns === []) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint) use ($legacyColumns): void {
                $blueprint->dropColumn($legacyColumns);
            });
        }

        foreach ($replacementKeys as $table => $keys) {
            foreach ($keys as $key) {
                if (Schema::hasIndex($table, $key['columns'], 'unique')) {
                    continue; // Already present, for example after a partially replayed run.
                }

                Schema::table($table, static function (Blueprint $blueprint) use ($key): void {
                    $blueprint->unique($key['columns'], $key['name']);
                });
            }
        }

        Schema::dropIfExists('legal_entities');
    }

    public function down(): void
    {
        // Forward-only: restore the pre-cutover schema and data from a snapshot.
    }

    private function hasLegacyColumn(string $table): bool
    {
        foreach (self::LEGACY_COLUMNS as $column) {
            if (Schema::hasColumn($table, $column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{columns: list<string>, name: string|null}>
     */
    private function replacementUniqueKeys(string $table): array
    {
        $keys = [];

        foreach (Schema::getIndexes($table) as $index) {
            if (($index['unique'] ?? false) !== true || ($index['primary'] ?? false) === true) {
                continue;
            }

            $columns = array_values(array_map(
                static fn (mixed $column): string => (string) $column,
                $index['columns'],
            ));

            if (! in_array($columns[0] ?? '', self::LEGACY_COLUMNS, true)) {
                continue;
            }

            $businessColumns = array_values(array_filter(
                $columns,
                static fn (string $column): bool => ! in_array($column, self::LEGACY_COLUMNS, true),
            ));

            if ($businessColumns === []) {
                // A key made only of tenant/entity columns has no single-company
                // replacement and must not be recreated as a duplicate-everything key.
                continue;
            }

            $signature = implode("\0", $businessColumns);
            if (array_key_exists($signature, $keys)) {
                continue;
            }

            $keys[$signature] = [
                'columns' => $businessColumns,
                'name' => $this->indexName($table, $businessColumns),
            ];
        }

        return array_values($keys);
    }

    private function dropDependentForeignKeys(string $table): void
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $columns = array_values(array_map(
                static fn (mixed $column): string => (string) $column,
                $foreignKey['columns'],
            ));

            if (array_intersect($columns, self::LEGACY_COLUMNS) === []) {
                continue;
            }

            $name = $foreignKey['name'] ?? null;
            $reference = is_string($name) && $name !== '' ? $name : $columns;

            Schema::table($table, static function (Blueprint $blueprint) use ($reference): void {
                $blueprint->dropForeign($reference);
            });
        }
    }

    private function dropDependentIndexes(string $table): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['primary'] ?? false) === true) {
                continue; // Never drop the primary key.
            }

            $columns = array_values(array_map(
                static fn (mixed $column): string => (string) $column,
                $index['columns'],
            ));

            if (array_intersect($columns, self::LEGACY_COLUMNS) === []) {
                continue;
            }

            $name = (string) $index['name'];
            Schema::table($table, static function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropIndex($name);
            });
        }
    }

    /**
     * Laravel's conventional name can exceed MySQL's 64-character identifier
     * limit once long business keys lose their tenant prefix. Keep the
     * conventional name when it fits; otherwise use a deterministic short name.
     *
     * @param  list<string>  $columns
     */
    private function indexName(string $table, array $columns): ?string
    {
        $conventional = strtolower($table.'_'.implode('_', $columns).'_unique');
        $conventional = str_replace(['-', '.'], '_', $conventional);

        if (strlen($conventional) <= 64) {
            return null;
        }

        return 'uniq_'.substr(hash('sha256', $table.'|'.implode('|', $columns)), 0, 32);
    }
};
