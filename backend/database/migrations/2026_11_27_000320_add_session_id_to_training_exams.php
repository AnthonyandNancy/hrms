<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M17: an exam version may target a concrete training session.
 *
 * The session remains optional for course-level exams. When it is present the
 * service verifies that the exam course and the session course are identical;
 * the column itself stays a nullable scalar because the session is live
 * activity evidence, not a duplicate catalogue key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_exams', function (Blueprint $table): void {
            $table->unsignedBigInteger('session_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('training_exams', function (Blueprint $table): void {
            $table->dropIndex(['session_id']);
            $table->dropColumn('session_id');
        });
    }
};
