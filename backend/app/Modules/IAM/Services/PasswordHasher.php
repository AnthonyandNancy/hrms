<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Argon2id-only password hashing. Verification rejects legacy algorithms
 * rather than silently accepting a weaker hash.
 */
final class PasswordHasher
{
    public function hash(string $plaintext): string
    {
        $hash = Hash::driver('argon2id')->make($plaintext, [
            'memory' => 65536,
            'time' => 4,
            'threads' => 1,
        ]);

        if (! str_starts_with($hash, '$argon2id$')) {
            throw new RuntimeException('Argon2id hashing is unavailable.');
        }

        return $hash;
    }

    public function verify(string $plaintext, ?string $hash): bool
    {
        if ($hash === null || $hash === '' || ! str_starts_with($hash, '$argon2id$')) {
            return false;
        }

        return Hash::driver('argon2id')->check($plaintext, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return ! str_starts_with($hash, '$argon2id$') || Hash::driver('argon2id')->needsRehash($hash);
    }
}
