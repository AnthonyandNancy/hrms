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

    // Module-owned local effects. Workflow resolves each through the adapter;
    // the owning module keeps the writer and verifies the approved case.
    'domain_effect_handlers' => [
        'people.employee.change.apply' => App\Modules\People\Services\EmployeeProfileChangeEffectHandler::class,
        App\Modules\Time\EffectHandlers\LeaveApprovalEffectHandler::class,
    ],

    'effect_types' => [
        'people.employee.change.apply',
        'time.leave.apply',
        'time.leave.reverse',
    ],
];
