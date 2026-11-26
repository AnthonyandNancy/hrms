<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('role_code', 64)->index();
            $table->string('page_code', 160);
            $table->timestamp('created_at');

            $table->unique(['role_code', 'page_code'], 'role_pages_role_page_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_pages');
    }
};
