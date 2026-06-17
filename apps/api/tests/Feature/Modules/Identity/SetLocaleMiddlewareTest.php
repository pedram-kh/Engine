<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Middleware\SetLocale;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    app()->setLocale('en');
});

/**
 * Run SetLocale against a synthetic request and return the locale it set.
 * The user is attached via the resolver (no persistence needed) and the
 * downstream closure captures `app()->getLocale()` after the middleware ran.
 */
function localeResolvedBy(?User $user, ?string $acceptLanguage): string
{
    $request = Request::create('/api/v1/me');
    if ($acceptLanguage !== null) {
        $request->headers->set('Accept-Language', $acceptLanguage);
    }
    if ($user !== null) {
        $request->setUserResolver(fn (): User => $user);
    }

    $captured = 'unset';
    (new SetLocale)->handle($request, function () use (&$captured): Response {
        $captured = app()->getLocale();

        return new Response;
    });

    return $captured;
}

// -----------------------------------------------------------------------------
// 1) Authenticated user's preferred_language wins.
// -----------------------------------------------------------------------------

it('uses the authenticated user preferred_language when it is a UI locale', function (string $locale): void {
    $user = User::factory()->make(['preferred_language' => $locale]);

    expect(localeResolvedBy($user, null))->toBe($locale);
})->with(['en', 'pt', 'it']);

it('user preferred_language wins over a conflicting Accept-Language header', function (): void {
    $user = User::factory()->make(['preferred_language' => 'it']);

    expect(localeResolvedBy($user, 'pt'))->toBe('it');
});

// -----------------------------------------------------------------------------
// 2) Fall back to Accept-Language, then to en.
// -----------------------------------------------------------------------------

it('falls back to a UI Accept-Language when the user has no renderable preference', function (): void {
    $user = User::factory()->make(['preferred_language' => null]);

    expect(localeResolvedBy($user, 'pt'))->toBe('pt');
});

it('ignores a preferred_language we cannot render and falls back', function (): void {
    // `ja` is not one of the 24 rendered UI locales.
    $user = User::factory()->make(['preferred_language' => 'ja']);

    expect(localeResolvedBy($user, 'it'))->toBe('it');
    expect(localeResolvedBy($user, null))->toBe('en');
});

it('uses Accept-Language for an anonymous request', function (): void {
    expect(localeResolvedBy(null, 'it'))->toBe('it');
});

it('clamps a non-UI Accept-Language to the en default', function (): void {
    expect(localeResolvedBy(null, 'ja'))->toBe('en');
});

it('defaults to en for an anonymous request with no Accept-Language', function (): void {
    expect(localeResolvedBy(null, null))->toBe('en');
});

it('matches a region-tagged Accept-Language to its UI base locale', function (): void {
    expect(localeResolvedBy(null, 'pt-BR'))->toBe('pt');
});
