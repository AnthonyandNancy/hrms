<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production birth is the explicit `hrms:install` command. The default
     * seeder is deliberately a no-op: `db:seed` must never create demo users,
     * employees or business data. Demo datasets require an explicit dev/test
     * `demo:seed` invocation against an isolated database.
     */
    public function run(): void
    {
        $this->command?->info('No default business fixtures or demo identities were seeded.');
    }
}
