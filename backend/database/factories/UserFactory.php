<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\PasswordHasher;
use Illuminate\Database\Eloquent\Factories\Factory;

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
        ])->afterCreating(static function (User $user): void {
            $now = app(\App\Shared\Time\Clock::class)->now();
            \App\Modules\IAM\Models\MfaFactor::query()->create([
                'user_id' => (int) $user->id,
                'type' => \App\Modules\IAM\Models\MfaFactor::TYPE_TOTP,
                'secret_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString(app(\App\Modules\IAM\Services\TotpService::class)->generateSecret()),
                'label' => 'factory-privileged',
                'confirmed_at' => $now,
            ]);
            $user->forceFill(['mfa_enrolled_at' => $now])->save();
        });
    }

    public function withoutMfa(): static
    {
        return $this->state(fn (): array => [
            'mfa_enrolled_at' => null,
        ]);
    }
}
