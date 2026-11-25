<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform kernel read models: dictionaries and typed system parameters.
 *
 * Owning module: Platform. Both tables are tenant-scoped; application code
 * reads them only through DictionaryReader / ParameterReader contracts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_dictionaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('type', 64);
            $table->string('code', 64);
            $table->string('label', 160);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'type', 'code']);
        });

        Schema::create('sys_parameters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('key', 96);
            $table->json('value_json');
            $table->string('scope', 32)->default('TENANT');
            $table->timestamps();
            $table->unique(['tenant_id', 'key', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_parameters');
        Schema::dropIfExists('sys_dictionaries');
    }
};
