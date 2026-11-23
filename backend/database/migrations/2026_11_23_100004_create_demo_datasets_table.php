<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_datasets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('dataset', 64);
            $table->string('status', 24);
            $table->string('mode', 24);
            $table->json('populated_json');
            $table->json('deferred_json');
            $table->char('fixture_hash', 64);
            $table->timestamp('seeded_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'dataset'], 'demo_datasets_tenant_dataset_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_datasets');
    }
};
