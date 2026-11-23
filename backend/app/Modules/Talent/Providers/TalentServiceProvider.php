<?php

declare(strict_types=1);

namespace App\Modules\Talent\Providers;

use App\Modules\Talent\Contracts\RecruitmentFunnelDirectory;
use App\Modules\Talent\Services\UnavailableRecruitmentFunnelDirectory;
use Illuminate\Support\ServiceProvider;

final class TalentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(RecruitmentFunnelDirectory::class, UnavailableRecruitmentFunnelDirectory::class);
    }
}
