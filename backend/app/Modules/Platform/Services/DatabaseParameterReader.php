<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\ParameterReader;
use App\Modules\Platform\Models\SystemParameter;

final class DatabaseParameterReader implements ParameterReader
{
    public function get(int $tenantId, string $key, mixed $default = null): mixed
    {
        $parameter = SystemParameter::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->where('scope', 'TENANT')
            ->first();

        if ($parameter === null) {
            return $default;
        }

        $value = (array) $parameter->value_json;

        return array_key_exists('value', $value) ? $value['value'] : $default;
    }
}
