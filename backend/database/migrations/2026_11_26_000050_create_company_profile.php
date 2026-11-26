<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_profile', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('country_code', 2)->default('CN');
            $table->string('timezone', 64)->default('Asia/Shanghai');
            $table->string('currency_code', 3)->default('CNY');
            $table->json('calendar_policy_json')->nullable();
            $table->timestamps();
        });

        // Singleton by construction: the first row is the only company profile.
        DB::table('company_profile')->insert([
            'id' => 1,
            'name' => 'Synthetic Company',
            'country_code' => 'CN',
            'timezone' => 'Asia/Shanghai',
            'currency_code' => 'CNY',
            'calendar_policy_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_profile');
    }
};
