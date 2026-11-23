<?php

declare(strict_types=1);

use App\Modules\Engagement\Providers\EngagementServiceProvider;
use App\Modules\IAM\Providers\IamServiceProvider;
use App\Modules\Lifecycle\Providers\LifecycleServiceProvider;
use App\Modules\Organization\Providers\OrganizationServiceProvider;
use App\Modules\Payroll\Providers\PayrollServiceProvider;
use App\Modules\People\Providers\PeopleServiceProvider;
use App\Modules\Platform\Providers\PlatformServiceProvider;
use App\Modules\Reporting\Providers\ReportingServiceProvider;
use App\Modules\Talent\Providers\TalentServiceProvider;
use App\Modules\Time\Providers\TimeServiceProvider;
use App\Modules\Workflow\Providers\WorkflowServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    IamServiceProvider::class,
    WorkflowServiceProvider::class,
    OrganizationServiceProvider::class,
    PeopleServiceProvider::class,
    TimeServiceProvider::class,
    PayrollServiceProvider::class,
    TalentServiceProvider::class,
    LifecycleServiceProvider::class,
    EngagementServiceProvider::class,
    ReportingServiceProvider::class,
];
