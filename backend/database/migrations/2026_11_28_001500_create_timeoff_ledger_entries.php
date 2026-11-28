<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeoff_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedBigInteger('overtime_settlement_id')->nullable()->unique();
            $table->string('entry_type', 16);
            $table->integer('quantity_minutes');
            $table->date('effective_on');
            $table->string('reason', 255);
            $table->unsignedBigInteger('created_by');
            $table->dateTime('recorded_at', 6);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->index(['employment_id', 'effective_on'], 'timeoff_ledger_balance_lookup');
        });

        DB::table('overtime_settlements')
            ->where('method', 'TIMEOFF')
            ->orderBy('id')
            ->each(function (object $settlement): void {
                if (DB::table('timeoff_ledger_entries')->where('overtime_settlement_id', (int) $settlement->id)->exists()) {
                    return;
                }
                $businessDate = DB::table('overtime_records')
                    ->where('id', (int) $settlement->overtime_record_id)
                    ->value('business_date');
                $recordedAt = (string) ($settlement->recorded_at ?? $settlement->created_at ?? date('Y-m-d H:i:s'));
                DB::table('timeoff_ledger_entries')->insert([
                    'employment_id' => (int) $settlement->employment_id,
                    'overtime_settlement_id' => (int) $settlement->id,
                    'entry_type' => 'EARN',
                    'quantity_minutes' => (int) $settlement->minutes,
                    'effective_on' => $businessDate !== null ? substr((string) $businessDate, 0, 10) : substr($recordedAt, 0, 10),
                    'reason' => (string) $settlement->reason,
                    'created_by' => 0,
                    'recorded_at' => $recordedAt,
                    'created_at' => $recordedAt,
                    'updated_at' => $recordedAt,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeoff_ledger_entries');
    }
};
