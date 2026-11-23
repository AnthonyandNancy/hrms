<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain;

/**
 * Canonical request hashing for idempotent Workflow commands. Object key order
 * is normalized; list order and scalar type remain significant so a different
 * payload cannot silently replay an earlier decision or case creation.
 */
final class IdempotencyHash
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function forPayload(array $payload): string
    {
        return hash('sha256', json_encode($this->normalize($payload), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function normalize(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload, SORT_STRING);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalize($value);
            }
        }

        return $payload;
    }
}
