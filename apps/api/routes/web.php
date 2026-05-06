<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn (): JsonResponse => response()->json([
    'name' => 'Catalyst Engine API',
    'status' => 'ok',
]));

Route::get('/health', static fn (): JsonResponse => response()->json([
    'status' => 'ok',
    'service' => 'catalyst-api',
    'timestamp' => now()->toIso8601String(),
]));
