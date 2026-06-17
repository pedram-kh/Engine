<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Core\Enums\Locale;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the application locale for the request so server-rendered
 * strings — API error messages (`trans('auth.*')`) and any mail sent
 * synchronously within the request — render in the caller's language
 * (docs/00-MASTER-ARCHITECTURE.md §13).
 *
 * Resolution order (first match wins):
 *   1. the authenticated user's `preferred_language` (when it is a locale
 *      we actually render — `Locale::UI_LOCALES`);
 *   2. the `Accept-Language` header, narrowed to `UI_LOCALES`;
 *   3. the default, `en`.
 *
 * Only `UI_LOCALES` are ever applied: a value we cannot render would leave
 * `trans()` falling back to `en` key-by-key, which is worse than rendering
 * a clean `en`. Content-language fields (the full 24 `EU_LANGUAGES`) are a
 * separate concern and never drive the UI locale.
 *
 * Ordering (registered in `bootstrap/app.php` immediately AFTER
 * `EnforceImpersonation`): under impersonation the impersonated user is the
 * one logged into the `web` guard at claim time, so by the time this runs
 * `$request->user()` is the ACTING user — the locale follows the person the
 * admin is impersonating, not the admin.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $user = $request->user();
        if ($user instanceof User) {
            $preferred = $user->preferred_language;
            if (is_string($preferred) && in_array($preferred, Locale::UI_LOCALES, true)) {
                return $preferred;
            }
        }

        // Narrowed to UI_LOCALES, so the result is always renderable. With no
        // (or no matching) `Accept-Language` header Symfony returns the FIRST
        // list entry, so `en` must lead the candidate list to remain the
        // default — UI_LOCALES is ordered alphabetically (bg first), not
        // en-first. A header that genuinely matches another UI locale still
        // wins over this leading default.
        $candidates = array_values(array_unique(['en', ...Locale::UI_LOCALES]));

        return $request->getPreferredLanguage($candidates) ?? 'en';
    }
}
