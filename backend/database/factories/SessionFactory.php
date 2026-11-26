<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\IAM\Models\Session;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Session>
 */
class SessionFactory extends Factory
{
    protected $model = Session::class;

    public function definition(): array
    {
        $now = now()->utc();

        return [
            'id' => (string) Str::ulid(),
            'user_id' => 1,
            'token_hash' => hash('sha256', Str::random(32)),
            'csrf_hash' => hash('sha256', Str::random(32)),
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'mfa_verified' => false,
            'auth_version' => 1,
            'idle_expires_at' => $now->addMinutes(30),
            'absolute_expires_at' => $now->addHours(12),
            'revoked_at' => null,
            'last_seen_at' => $now,
            'created_at' => $now,
        ];
    }
}
