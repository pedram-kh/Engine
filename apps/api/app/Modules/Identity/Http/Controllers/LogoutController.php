<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/v1/auth/logout
 * POST /api/v1/admin/auth/logout
 *
 * Returns 204 No Content on success per docs/04-API-DESIGN.md §8 status
 * code conventions ("Success with no body — e.g., DELETE / logout").
 */
final class LogoutController
{
    public function __invoke(Request $request, AuthService $auth): Response
    {
        $guard = self::resolveGuard($request);

        $auth->logout($request, $guard);

        return new Response(status: 204);
    }

    private static function resolveGuard(Request $request): string
    {
        $route = $request->route();

        if ($route !== null) {
            $name = $route->getName();
            if (is_string($name) && str_starts_with($name, 'admin.')) {
                return 'web_admin';
            }
        }

        return 'web';
    }
}
