<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\MetaController;
use Illuminate\Support\Facades\Route;

Route::get('/', MetaController::class);
