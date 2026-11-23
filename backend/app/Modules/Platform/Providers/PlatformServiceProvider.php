<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Contracts\OutboxDispatcher;
use App\Modules\Platform\Services\LogOutboxDispatcher;
use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OutboxDispatcher::class, LogOutboxDispatcher::class);
    }
}
