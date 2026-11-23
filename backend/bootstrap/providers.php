<?php

declare(strict_types=1);

use App\Modules\IAM\Providers\IamServiceProvider;
use App\Modules\Platform\Providers\PlatformServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    IamServiceProvider::class,
];
