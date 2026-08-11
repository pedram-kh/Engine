<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Install-time tooling must never mutate .env or a secret (AH-067)
|--------------------------------------------------------------------------
|
| The incident (2026-08-11, recorded in docs/runbooks/deploy-log.md's
| 2026-08-11 entry): `post-install-cmd` carried both the .env-bootstrap copy
| AND `artisan key:generate`. Stock Laravel scopes those to
| `post-root-package-install` and `post-create-project-cmd` respectively —
| hooks that fire ONLY on `composer create-project` (first scaffold), never
| on a plain `composer install`. This project's copy of `post-install-cmd`
| fires on EVERY `composer install`, including a production deploy's
| `composer install --no-dev` — so every deploy silently rotated the live
| APP_KEY, taking every TOTP/MFA secret and session with it.
|
| Bug class this prevents: a future scaffold copy-paste (or a "helpful"
| onboarding-friction fix) re-adds a .env-bootstrap or key:generate line to
| post-install-cmd, post-update-cmd, or post-autoload-dump — the three hooks
| Composer runs on every install/update, not just at project creation.
|
| Break-revert verification: temporarily re-add
| `"@php artisan key:generate --ansi"` to `post-install-cmd` in
| composer.json; this test must fail. Revert and confirm pass.
|
*/

/** @return array<string, mixed> */
function composerJson(): array
{
    $path = base_path('composer.json');
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}");
    }

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

it('post-install-cmd, post-update-cmd, and post-autoload-dump contain no key:generate or .env-mutating command', function (): void {
    $scripts = composerJson()['scripts'] ?? [];

    // These three hooks fire on EVERY `composer install`/`update`, including
    // a production deploy — unlike post-root-package-install and
    // post-create-project-cmd, which fire only once, at project scaffolding.
    $everyInstallHooks = ['post-install-cmd', 'post-update-cmd', 'post-autoload-dump'];

    foreach ($everyInstallHooks as $hook) {
        $commands = $scripts[$hook] ?? [];

        expect($commands)->toBeArray();

        foreach ($commands as $command) {
            expect($command)->toBeString();
            expect(str_contains($command, 'key:generate'))->toBeFalse(
                "composer.json '{$hook}' must never call key:generate — it runs on every deploy's ".
                'composer install, not just project creation (the 2026-08-11 APP_KEY incident).',
            );
            expect(str_contains($command, '.env'))->toBeFalse(
                "composer.json '{$hook}' must never reference .env — install-time tooling that fires ".
                'on every deploy must not be able to mutate secrets or config (AH-067).',
            );
        }
    }
});

it('key:generate and the .env bootstrap stay scoped to project-creation-only hooks', function (): void {
    // Counter-test: the fix must not simply delete these commands outright —
    // a fresh `composer create-project` still needs them. They belong ONLY
    // in the hooks Composer fires once, at scaffolding time.
    $scripts = composerJson()['scripts'] ?? [];

    $createProjectCmds = $scripts['post-create-project-cmd'] ?? [];
    expect($createProjectCmds)->toBeArray();
    expect(array_filter($createProjectCmds, static fn (string $c): bool => str_contains($c, 'key:generate')))
        ->not->toBeEmpty('post-create-project-cmd must still run key:generate for a fresh scaffold.');

    $rootPackageCmds = $scripts['post-root-package-install'] ?? [];
    expect($rootPackageCmds)->toBeArray();
    expect(array_filter($rootPackageCmds, static fn (string $c): bool => str_contains($c, '.env')))
        ->not->toBeEmpty('post-root-package-install must still bootstrap .env for a fresh scaffold.');
});
