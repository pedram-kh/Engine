<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Controllers;

use App\Modules\Identity\Features\AccountDeletionEnabled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

/**
 * POST   /api/v1/_test/feature-flag/{name}   — turn the flag ON for everyone
 * DELETE /api/v1/_test/feature-flag/{name}   — turn it OFF for everyone
 *
 * AH-104d. The wizard account menu and the pending page's delete link follow
 * `account_deletion_enabled`, so the Playwright leg has to drive both states.
 * The first version of this fixture shelled out to `php artisan tinker` from the
 * Playwright process and refused unless `DB_DATABASE` contained "e2e"; CI's
 * throwaway Postgres is named `catalyst`, so the guard (correctly) refused there.
 * Going through the API process removes the shell-out and the name test: the call
 * lands in whichever database the API under test is bound to, and a run against
 * the developer's own API is already refused by the Playwright config
 * (`reuseExistingServer: false`).
 *
 * ALLOW-LIST, not "any flag"
 * --------------------------
 * Only the names in {@see self::ALLOWED_NAMES} can be switched. A flag is a switch
 * for production behaviour; the helper surface must not become a way to flip an
 * arbitrary one, and a typo in a spec must fail (422) rather than quietly switch
 * nothing. Adding a flag here is a deliberate, reviewed act.
 *
 * REQUIRES EXPLICIT afterEach RESTORE
 * -----------------------------------
 * The state lives in the Pennant store (the database) and survives across specs.
 * Every spec that POSTs here MUST DELETE in `afterEach`, exactly as for the
 * rate-limiter neutraliser and the test clock.
 *
 * Production-safety perimeter: the same layered gate as the rest of `_test/*`
 * (env in {local,testing}, a non-empty `TEST_HELPERS_TOKEN`, the token header).
 */
final class SetFeatureFlagController
{
    /** @var list<string> */
    public const ALLOWED_NAMES = [
        AccountDeletionEnabled::NAME,
    ];

    public function store(Request $request, string $name): JsonResponse
    {
        if (! in_array($name, self::ALLOWED_NAMES, true)) {
            return $this->unknownName($name);
        }

        Feature::activate($name);

        return $this->state($name);
    }

    public function destroy(Request $request, string $name): JsonResponse
    {
        if (! in_array($name, self::ALLOWED_NAMES, true)) {
            return $this->unknownName($name);
        }

        Feature::deactivate($name);

        return $this->state($name);
    }

    private function state(string $name): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'name' => $name,
                'active' => Feature::active($name),
            ],
        ]);
    }

    private function unknownName(string $name): JsonResponse
    {
        return new JsonResponse([
            'error' => sprintf(
                'unknown feature flag "%s"; allowed names: %s',
                $name,
                implode(', ', self::ALLOWED_NAMES),
            ),
        ], 422);
    }
}
