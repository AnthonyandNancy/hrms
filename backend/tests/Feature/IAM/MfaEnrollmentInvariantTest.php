<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MfaEnrollmentInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_flagged_enrolled_without_a_factor_is_reported(): void
    {
        $user = User::factory()->create(['mfa_enrolled_at' => now(), 'auth_version' => 1]);

        $this->artisan('iam:verify-mfa-enrollment')
            ->expectsOutputToContain((string) $user->username)
            ->assertExitCode(1);
    }

    public function test_a_user_with_a_confirmed_factor_and_a_timestamp_passes(): void
    {
        $user = User::factory()->create(['mfa_enrolled_at' => now(), 'auth_version' => 1]);
        MfaFactor::query()->create([
            'user_id' => (int) $user->id,
            'type' => MfaFactor::TYPE_TOTP,
            'secret_ciphertext' => 'x',
            'label' => 'TOTP',
            'confirmed_at' => now(),
        ]);

        $this->artisan('iam:verify-mfa-enrollment')->assertExitCode(0);
    }

    public function test_a_user_with_a_factor_but_no_timestamp_is_reported(): void
    {
        $user = User::factory()->create(['mfa_enrolled_at' => null, 'auth_version' => 1]);
        MfaFactor::query()->create([
            'user_id' => (int) $user->id,
            'type' => MfaFactor::TYPE_TOTP,
            'secret_ciphertext' => 'x',
            'label' => 'TOTP',
            'confirmed_at' => now(),
        ]);

        $this->artisan('iam:verify-mfa-enrollment')->assertExitCode(1);
    }

    public function test_a_disabled_factor_does_not_satisfy_the_invariant(): void
    {
        $user = User::factory()->create(['mfa_enrolled_at' => now(), 'auth_version' => 1]);
        MfaFactor::query()->create([
            'user_id' => (int) $user->id,
            'type' => MfaFactor::TYPE_TOTP,
            'secret_ciphertext' => 'x',
            'label' => 'TOTP',
            'confirmed_at' => now(),
            'disabled_at' => now(),
        ]);

        $this->artisan('iam:verify-mfa-enrollment')
            ->expectsOutputToContain((string) $user->username)
            ->assertExitCode(1);
    }
}
