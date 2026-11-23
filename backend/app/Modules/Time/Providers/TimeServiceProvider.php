<?php

declare(strict_types=1);

namespace App\Modules\Time\Providers;

use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\Time\Adapters\PeopleEmploymentDirectory;
use App\Modules\Time\Adapters\WorkflowCaseAdapter;
use App\Modules\Time\Contracts\EmploymentDirectory;
use App\Modules\Time\Contracts\LegalEntityCalendarDirectory;
use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Services\BusinessCalendar;
use App\Modules\Workflow\Contracts\CaseCommandPort;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class TimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmploymentDirectory::class, function (Application $app): PeopleEmploymentDirectory {
            return new PeopleEmploymentDirectory($app->make(PeopleDirectory::class));
        });

        $this->app->bind(LegalEntityCalendarDirectory::class, BusinessCalendar::class);

        $this->app->bind(WorkflowCasePort::class, function (Application $app): WorkflowCaseAdapter {
            return new WorkflowCaseAdapter(
                $app->make(CaseCommandPort::class),
                $app->make(WorkflowPrincipalResolver::class),
            );
        });
    }
}
