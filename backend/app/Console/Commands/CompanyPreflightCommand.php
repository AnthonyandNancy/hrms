<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only inventory for the approved single-company rebuild.
 *
 * The system is not live. The cutover deletes the legacy database and rebuilds
 * it with `migrate:fresh --seed`; this command deliberately makes no merge,
 * preservation or duplicate-key decision.
 */
final class CompanyPreflightCommand extends Command
{
    protected $signature = 'company:preflight {--json : Output JSON}';

    protected $description = 'Inventory the legacy schema and data before the single-company rebuild.';

    public function handle(): int
    {
        $report = $this->buildReport();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        $this->line('connection: '.$report['connection']);
        $this->line('data_disposition: '.$report['data_disposition']);
        $this->line('company_profile_rows: '.$report['company_profile_rows']);
        $this->line('legal_entity_bindings: '.$report['legal_entity_bindings']);
        $this->table(['table', 'rows', 'legacy columns'], array_map(
            static fn (array $row): array => [
                $row['table'],
                $row['rows'],
                implode(',', $row['legacy_columns']),
            ],
            $report['tables'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     connection: string,
     *     data_disposition: string,
     *     company_profile_rows: int,
     *     legal_entity_bindings: int,
     *     tables: list<array{table: string, rows: int, legacy_columns: list<string>}>
     * }
     */
    public function buildReport(): array
    {
        // Driver-neutral inventory: Schema::getTables() plus Schema::hasColumn()
        // runs on SQLite in tests and on MySQL 8.4 in the rehearsal stack.
        $tables = collect(Schema::getTables())
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->sort()
            ->values();

        return [
            'connection' => (string) DB::connection()->getDriverName(),
            'data_disposition' => 'REBUILD',
            'company_profile_rows' => Schema::hasTable('company_profile')
                ? DB::table('company_profile')->count()
                : 0,
            'legal_entity_bindings' => Schema::hasTable('authority_bindings')
                ? DB::table('authority_bindings')->where('anchor_type', 'LEGAL_ENTITY')->count() // single-company:preflight-only
                : 0,
            'tables' => $tables->map(function (string $table): array {
                $legacy = array_values(array_filter(
                    ['tenant_id', 'legal_entity_id'], // single-company:preflight-only
                    static fn (string $column): bool => Schema::hasColumn($table, $column),
                ));

                return [
                    'table' => $table,
                    'rows' => DB::table($table)->count(),
                    'legacy_columns' => $legacy,
                ];
            })->all(),
        ];
    }
}
