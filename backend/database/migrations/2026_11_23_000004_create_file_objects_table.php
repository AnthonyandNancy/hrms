<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_objects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('storage_key', 512)->unique();
            $table->char('sha256', 64)->index();
            $table->string('original_name', 255)->nullable();
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('owner_user_id')->nullable()->index();
            $table->string('purpose', 64)->index();
            $table->string('retention_class', 32);
            $table->boolean('legal_hold')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_objects');
    }
};
