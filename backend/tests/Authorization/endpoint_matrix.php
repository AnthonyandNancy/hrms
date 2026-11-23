<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authorization negative-test endpoint matrix
|--------------------------------------------------------------------------
|
| Every guarded /v1 route should have an entry keyed by "METHOD /v1/path".
| capability:audit warns (but does not fail) when a registered route is
| missing here. Later tasks extend this file with the four mandatory
| negative cases per endpoint:
|   unauthenticated, no capability, wrong anchor, field denial.
|
*/

return [
    'GET /v1/health' => [
        'public' => true,
        'negative_tests' => [],
    ],
    'GET /v1/meta' => [
        'public' => true,
        'negative_tests' => [],
    ],
];
