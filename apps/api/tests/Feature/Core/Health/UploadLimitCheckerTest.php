<?php

declare(strict_types=1);

use App\Core\Health\UploadLimitChecker;
use Tests\TestCase;

uses(TestCase::class);

it('parses ini shorthand byte values', function (string $raw, int $expected): void {
    expect(UploadLimitChecker::parseBytes($raw))->toBe($expected);
})->with([
    'bytes' => ['1048576', 1048576],
    'kilobytes' => ['512K', 512 * 1024],
    'megabytes' => ['2M', 2 * 1024 * 1024],
    'gigabytes' => ['1G', 1024 * 1024 * 1024],
    'zero (unlimited)' => ['0', 0],
    'empty' => ['', 0],
    'whitespace' => ['  8M ', 8 * 1024 * 1024],
]);

it('requiredBytes reflects the configured single source of truth', function (): void {
    config([
        'uploads.avatar_max_bytes' => 4 * 1024 * 1024,
        'uploads.brand_logo_max_bytes' => 1 * 1024 * 1024,
    ]);

    expect((new UploadLimitChecker)->requiredBytes())->toBe(4 * 1024 * 1024);
});

/**
 * AH-053 (Q2). With more than one upload surface, the health assertion has to
 * cover the LARGEST advertised cap — otherwise raising a non-avatar cap above
 * the runtime ceiling would leave /health reporting "ok" while those uploads
 * silently fail.
 */
it('requiredBytes is the largest registered cap, whichever surface it belongs to', function (): void {
    config([
        'uploads.avatar_max_bytes' => 1 * 1024 * 1024,
        'uploads.brand_logo_max_bytes' => 7 * 1024 * 1024,
    ]);

    expect((new UploadLimitChecker)->requiredBytes())->toBe(7 * 1024 * 1024);
});

it('flags an unsatisfiable runtime when ANY registered cap exceeds the ceiling', function (): void {
    config([
        'uploads.avatar_max_bytes' => 1,
        'uploads.brand_logo_max_bytes' => PHP_INT_MAX,
    ]);

    expect((new UploadLimitChecker)->isSatisfied())->toBeFalse();
});

it('isSatisfied is true when the app cap fits under the runtime ceiling', function (): void {
    config(['uploads.avatar_max_bytes' => 1]);

    expect((new UploadLimitChecker)->isSatisfied())->toBeTrue();
});

it('isSatisfied is false when the app cap exceeds the runtime ceiling', function (): void {
    config(['uploads.avatar_max_bytes' => PHP_INT_MAX]);

    $checker = new UploadLimitChecker;

    expect($checker->isSatisfied())->toBeFalse()
        ->and($checker->effectiveCeilingBytes())->toBeLessThan(PHP_INT_MAX);
});
