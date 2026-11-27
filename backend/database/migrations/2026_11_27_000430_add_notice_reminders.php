<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notice reminders keep delivery evidence separate from read receipts.
 *
 * The unique business key prevents the same requester from creating duplicate
 * reminder notifications for the same notice recipient. A later notification
 * row is recorded once and replayed by the delivery command instead of being
 * recreated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_reminders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('notice_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('requested_by')->index();
            $table->unsignedBigInteger('notification_id')->nullable()->index();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(
                ['notice_id', 'user_id', 'requested_by'],
                'notice_reminder_unique',
            );
            $table->index(
                ['notice_id', 'recorded_at'],
                'notice_reminder_notice_recorded_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_reminders');
    }
};
