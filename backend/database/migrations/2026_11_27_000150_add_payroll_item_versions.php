<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned payroll-item facts.
 *
 * payroll_items keeps the stable identity (code + lifecycle status). Every
 * mutable business attribute lives on an append-only version row. Existing
 * items are backfilled with a version 1 that starts at the earliest known
 * scheme effective date (or 2026-01-01 when the item was never referenced).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_item_versions')) {
            Schema::create('payroll_item_versions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('payroll_item_id')->index();
                $table->unsignedInteger('version');
                $table->string('name', 160);
                $table->string('type', 24);
                $table->boolean('taxable')->default(true);
                $table->boolean('insurable')->default(true);
                $table->string('calculation', 32)->default('FIXED');
                $table->json('formula_json')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->date('effective_from');
                $table->date('effective_to')->default('9999-12-31');
                $table->dateTime('retired_at', 6)->nullable();
                $table->string('retire_reason', 500)->nullable();
                $table->string('status', 24)->default('ACTIVE');
                $table->dateTime('recorded_at', 6);
                $table->timestamps(6);

                $table->unique(['payroll_item_id', 'version'], 'payroll_item_version_unique');
                $table->index(['payroll_item_id', 'effective_from'], 'payroll_item_effective_idx');
            });
        }

        $this->backfill();
        $this->dropLegacyCatalogColumns();
    }

    public function down(): void
    {
        // Forward-only catalog cutover: the legacy columns are not recreated.
        // Restore them from the pre-migration database snapshot if needed.
        Schema::dropIfExists('payroll_item_versions');
    }

    /**
     * After every item has a version-1 row, payroll_items is reduced to the
     * stable identity (id, code, status) plus timestamps. All mutable item
     * facts now live on payroll_item_versions only.
     */
    private function dropLegacyCatalogColumns(): void
    {
        $legacyColumns = [
            'name',
            'type',
            'taxable',
            'insurable',
            'calculation',
            'formula_json',
            'sort_order',
        ];

        $present = array_values(array_filter(
            $legacyColumns,
            static fn (string $column): bool => Schema::hasColumn('payroll_items', $column),
        ));

        if ($present === []) {
            return;
        }

        Schema::table('payroll_items', static function (Blueprint $table) use ($present): void {
            $table->dropColumn($present);
        });
    }

    private function backfill(): void
    {
        $now = now('UTC')->format('Y-m-d H:i:s.u');
        $items = DB::table('payroll_items')->orderBy('id')->get();

        foreach ($items as $item) {
            $exists = DB::table('payroll_item_versions')
                ->where('payroll_item_id', (int) $item->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $earliest = DB::table('payroll_scheme_items')
                ->join('payroll_schemes', 'payroll_schemes.id', '=', 'payroll_scheme_items.scheme_id')
                ->where('payroll_scheme_items.payroll_item_id', (int) $item->id)
                ->min('payroll_schemes.valid_from');

            $effectiveFrom = is_string($earliest) && $earliest !== ''
                ? substr($earliest, 0, 10)
                : '2026-01-01';

            DB::table('payroll_item_versions')->insert([
                'payroll_item_id' => (int) $item->id,
                'version' => 1,
                'name' => (string) $item->name,
                'type' => (string) $item->type,
                'taxable' => (bool) $item->taxable,
                'insurable' => (bool) $item->insurable,
                'calculation' => (string) $item->calculation,
                'formula_json' => $item->formula_json !== null ? (string) $item->formula_json : null,
                'sort_order' => (int) $item->sort_order,
                'effective_from' => $effectiveFrom,
                'effective_to' => '9999-12-31',
                'retired_at' => null,
                'retire_reason' => null,
                'status' => (string) $item->status,
                'recorded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
