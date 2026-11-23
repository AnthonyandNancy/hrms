<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\IAM\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Modules\IAM\Services\PasswordHasher;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $passwordHash = null;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => self::$passwordHash ??= app(PasswordHasher::class)->hash('Correct-Horse-9!'),
            'status' => User::STATUS_ACTIVE,
            'auth_version' => 1,
            'is_privileged' => false,
        ];
    }

    public function privileged(): static
    {
        return $this->state(fn (): array => [
            'is_privileged' => true,
            'mfa_enrolled_at' => now(),
        ]);
    }

    public function withoutMfa(): static
    {
        return $this->state(fn (): array => [
            'mfa_enrolled_at' => null,
        ]);
    }
}
