<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publication (payslip visibility/YTD) and disbursement (bank/receipt
 * registration) are orthogonal facts. Lifecycle status remains for
 * compatibility; close requires both explicit flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->string('payslip_status', 24)->default('UNPUBLISHED');
            $table->string('disbursement_status', 24)->default('NOT_REGISTERED');
            $table->string('disbursement_reference', 160)->nullable();
            $table->unsignedBigInteger('disbursement_registered_by')->nullable();
            $table->dateTime('disbursement_registered_at', 6)->nullable();
        });

        DB::table('payroll_runs')->whereIn('status', ['PUBLISHED', 'PAID', 'CLOSED'])->update([
            'payslip_status' => 'PUBLISHED',
        ]);
        DB::table('payroll_runs')->whereIn('status', ['PAID', 'CLOSED'])->update([
            'disbursement_status' => 'REGISTERED',
            'disbursement_registered_at' => DB::raw('paid_at'),
            'disbursement_registered_by' => DB::raw('paid_by'),
        ]);
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'payslip_status',
                'disbursement_status',
                'disbursement_reference',
                'disbursement_registered_by',
                'disbursement_registered_at',
            ]);
        });
    }
};
