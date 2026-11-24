<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * The default seeder delegates to the demo seeder, which creates real TOTP
     * factors before writing `mfa_enrolled_at`. It never writes the timestamp
     * on its own (the E-08 defect).
     */
    public function run(): void
    {
        $exit = Artisan::call('iam:seed-demo');

        if ($exit !== 0) {
            $this->command?->warn('iam:seed-demo did not run; set DEMO_SEED_PASSWORD (>=12 chars) to seed demo data.');
        }
    }
}
