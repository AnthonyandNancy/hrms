<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\IAM\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds. Never use a hard-coded production password.
     */
    public function run(): void
    {
        $password = config('iam.demo_seed_password');

        if (! is_string($password) || strlen($password) < 12) {
            $this->command?->warn('DEMO_SEED_PASSWORD is not set; no demo user was seeded.');

            return;
        }

        User::query()->firstOrCreate(
            ['tenant_id' => 1, 'username' => 'demo.admin'],
            [
                'email' => 'demo.admin@example.test',
                'password_hash' => Hash::make($password),
                'status' => User::STATUS_ACTIVE,
                'is_privileged' => true,
                'mfa_enrolled_at' => now(),
            ],
        );
    }
}
