<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 Routes
|--------------------------------------------------------------------------
|
| Module routes are mounted by each module's ServiceProvider into the
| 'api' middleware group, prefixed by '/api/v1'. This file is intentionally
| minimal — modules own their own route files under
| app/Modules/<Module>/Routes/api.php.
|
*/

Route::get('/v1/ping', static fn (Request $request): JsonResponse => response()->json([
    'pong' => true,
    'time' => now()->toIso8601String(),
]));
