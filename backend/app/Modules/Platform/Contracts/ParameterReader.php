<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

/**
 * Read port for typed company parameters.
 *
 * There is one company, so a parameter has exactly one effective value. The
 * storage scope is retained as a versioned diagnostic field, not as a
 * client-selectable data boundary.
 *
 * Storage shape: `value_json` must be a JSON object containing a `value` key,
 * for example `{"value": 200}`. Rows that do not follow that shape are treated
 * as absent (the caller default is returned) rather than surfacing a malformed
 * value to business code. A stored JSON `null` is a real value, not "absent".
 */
interface ParameterReader
{
    public function get(string $key, mixed $default = null): mixed;
}
