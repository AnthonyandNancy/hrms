<?php

declare(strict_types=1);

use App\Modules\Platform\Providers\PlatformServiceProvider;
use App\Modules\Workflow\Providers\WorkflowServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    WorkflowServiceProvider::class,
];
