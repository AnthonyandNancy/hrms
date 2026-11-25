<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisition_openings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('requisition_id')->index();
            $table->string('channel', 32);
            $table->string('status', 24)->default('OPEN');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->dateTime('recorded_at', 6);
            $table->timestamps();

            $table->unique(['tenant_id', 'requisition_id', 'channel'], 'requisition_opening_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_openings');
    }
};
