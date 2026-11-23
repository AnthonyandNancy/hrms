<?php

declare(strict_types=1);

return [
    // Synthetic reference policy. Real limits are tenant/legal-entity policy
    // parameters and must not be inferred from the calendar month.
    'probation_max_days' => env('HRMS_PROBATION_MAX_DAYS', 180),
    'early_confirmation_max_days' => env('HRMS_EARLY_CONFIRMATION_MAX_DAYS', 30),
    'training_wait_seconds' => env('HRMS_TRAINING_WAIT_SECONDS', 10),
];
