<?php

declare(strict_types=1);

use App\Modules\People\Providers\PeopleServiceProvider;
use App\Modules\Platform\Providers\PlatformServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    PeopleServiceProvider::class,
];
