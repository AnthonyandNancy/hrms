<?php

declare(strict_types=1);

use App\Modules\IAM\Providers\IamServiceProvider;
use App\Modules\Organization\Providers\OrganizationServiceProvider;
use App\Modules\People\Providers\PeopleServiceProvider;
use App\Modules\Platform\Providers\PlatformServiceProvider;
use App\Modules\Workflow\Providers\WorkflowServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    IamServiceProvider::class,
    WorkflowServiceProvider::class,
    OrganizationServiceProvider::class,
    PeopleServiceProvider::class,
];
