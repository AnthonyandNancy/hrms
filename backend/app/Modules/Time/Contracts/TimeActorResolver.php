<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

use Illuminate\Http\Request;

interface TimeActorResolver
{
    public function resolve(Request $request): ?TimeActor;
}
