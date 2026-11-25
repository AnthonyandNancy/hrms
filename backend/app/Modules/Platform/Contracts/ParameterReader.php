<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

/**
 * Read port for typed tenant parameters.
 *
 * Scope semantics (ADR-0031 decision 4): parameters are layered, most specific
 * first. `get()` resolves the requested scope, then falls back through
 * `LEGAL_ENTITY` to `TENANT`, so an override never silently disappears.
 *
 * Storage shape: `value_json` must be a JSON object containing a `value` key,
 * for example `{"value": 200}`. Rows that do not follow that shape are treated
 * as absent (the caller default is returned) rather than surfacing a malformed
 * value to business code. A stored JSON `null` is a real value, not "absent".
 */
interface ParameterReader
{
    /**
     * @param  array{legal_entity_id?: int}  $context  Anchor for scope resolution.
     */
    public function get(int $tenantId, string $key, mixed $default = null, array $context = []): mixed;
}
