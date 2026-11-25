<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_trip_records', function (Blueprint $table): void {
            $table->string('destination', 160)->nullable()->after('to_date');
            $table->string('purpose', 500)->nullable()->after('destination');
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_trip_records', function (Blueprint $table): void {
            $table->dropColumn(['destination', 'purpose', 'cancel_reason', 'cancelled_at']);
        });
    }
};
