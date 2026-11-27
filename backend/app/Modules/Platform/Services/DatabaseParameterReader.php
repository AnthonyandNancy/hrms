<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\ParameterReader;
use App\Modules\Platform\Models\SystemParameter;

final class DatabaseParameterReader implements ParameterReader
{
    public function get(string $key, mixed $default = null): mixed
    {
        $reading = $this->read($key);

        return $reading['found'] ? $reading['value'] : $default;
    }

    /**
     * @return array{found: bool, value: mixed}
     */
    private function read(string $key): array
    {
        $parameter = SystemParameter::query()
            ->where('key', $key)
            ->first();

        if ($parameter === null) {
            return ['found' => false, 'value' => null];
        }

        $payload = $parameter->decodedValue();

        if (! array_key_exists('value', $payload)) {
            return ['found' => false, 'value' => null];
        }

        return ['found' => true, 'value' => $payload['value']];
    }
}
