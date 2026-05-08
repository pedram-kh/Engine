<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

/**
 * Chunk 5 priority #1: TwoFactorService must be the ONLY class in
 * `app/` that touches the underlying TOTP / QR libraries. Controllers,
 * middleware, listeners, services, and any future module reach the
 * libraries through it.
 *
 * This test is the executable form of that contract. It walks every
 * .php file under `app/` and asserts that any reference to
 * `PragmaRX\Google2FA\` or `BaconQrCode\` is confined to
 * `app/Modules/Identity/Services/TwoFactorService.php`.
 *
 * If you legitimately need to add a new entry point, extend
 * TwoFactorService rather than reaching past it. If a follow-up chunk
 * needs raw access (e.g. an admin tool that re-renders an existing
 * QR code), add a method to the service and call it.
 */
it('confines pragmarx\\Google2FA usage in app/ to TwoFactorService.php', function (): void {
    $offenders = filesUnderAppContaining('PragmaRX\\Google2FA');

    expect($offenders)->toBe([
        'app/Modules/Identity/Services/TwoFactorService.php',
    ], 'Google2FA must only be referenced inside TwoFactorService.');
});

it('confines BaconQrCode usage in app/ to TwoFactorService.php', function (): void {
    $offenders = filesUnderAppContaining('BaconQrCode\\');

    expect($offenders)->toBe([
        'app/Modules/Identity/Services/TwoFactorService.php',
    ], 'BaconQrCode must only be referenced inside TwoFactorService.');
});

/**
 * @return list<string> repo-relative paths, sorted, that contain the needle
 */
function filesUnderAppContaining(string $needle): array
{
    $root = base_path('app');
    $hits = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        if (str_contains($contents, $needle)) {
            $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $hits[] = $relative;
        }
    }

    sort($hits);

    return $hits;
}
