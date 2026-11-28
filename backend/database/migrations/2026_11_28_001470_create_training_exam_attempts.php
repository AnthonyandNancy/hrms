<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_exam_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exam_id')->index();
            $table->unsignedBigInteger('session_id')->nullable()->index();
            $table->unsignedBigInteger('enrollment_id')->index();
            $table->unsignedBigInteger('employment_id')->index();
            $table->unsignedInteger('attempt_no');
            $table->unsignedSmallInteger('score');
            $table->boolean('passed');
            $table->json('answers_json')->nullable();
            $table->char('source_hash', 64);
            $table->dateTime('submitted_at', 6);
            $table->unsignedBigInteger('recorded_by');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->unique(['exam_id', 'enrollment_id', 'attempt_no'], 'training_exam_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_exam_attempts');
    }
};
