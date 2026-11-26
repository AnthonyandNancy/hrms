<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\ParameterReader;
use App\Modules\Platform\Models\SystemParameter;

final class DatabaseParameterReader implements ParameterReader
{
    /**
     * Scopes are resolved most-specific first. A legal-entity override wins
     * over the tenant default; a missing scope falls through to the next one.
     *
     * @param  array{legal_entity_id?: int}  $context
     */
    public function get(int $tenantId, string $key, mixed $default = null, array $context = []): mixed
    {
        $scopes = ['TENANT'];

        if (isset($context['legal_entity_id'])) {
            array_unshift($scopes, 'COMPANY:'.$context['legal_entity_id']);
        }

        foreach ($scopes as $scope) {
            $reading = $this->read($tenantId, $key, $scope);

            if ($reading['found']) {
                return $reading['value'];
            }
        }

        return $default;
    }

    /**
     * @return array{found: bool, value: mixed}
     */
    private function read(int $tenantId, string $key, string $scope): array
    {
        $parameter = SystemParameter::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->where('scope', $scope)
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
