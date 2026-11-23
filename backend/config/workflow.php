<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Effect handler bindings
    |--------------------------------------------------------------------------
    |
    | Workflow ships with a deterministic no-op handler. Integration tasks
    | append module-specific handlers here; unknown types are rejected at
    | decision time using that handler catalog.
    |
    */
    'effect_handlers' => [],

    'effect_types' => [],
];
