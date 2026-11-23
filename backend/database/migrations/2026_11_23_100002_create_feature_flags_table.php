<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('flag', 64);
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('reason', 255)->default('');
            $table->timestamps();

            $table->unique(['tenant_id', 'flag'], 'feature_flags_tenant_flag_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
