<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104c C1-2 — the claim cannot be reached around (architecture specs)
|--------------------------------------------------------------------------
|
| PHP has no "friend", so the guarantee that only the gate makes a claim is a source
| scan, in the style of the engine's "no model in Execution/" spec. The scans read
| `app/` only: the specs' own helper (`Tests\Support\ErasureClaims`) is the sanctioned
| way to hand the engine a claim without deciding anything.
*/

/** @return array<string, string> relative path => contents, for every php file under app/ */
function claimGuardSources(): array
{
    $out = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $out[str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
        }
    }

    return $out;
}

/** @param  list<string>  $allowed */
function claimGuardOffenders(string $pattern, array $allowed): array
{
    $offenders = [];
    foreach (claimGuardSources() as $path => $source) {
        if (preg_match($pattern, $source) === 1 && ! in_array($path, $allowed, true)) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

it('lets only the claimer build a claim', function (): void {
    expect(claimGuardOffenders('/new\s+(\\\\?App\\\\Modules\\\\Erasure\\\\Claim\\\\)?ErasureClaim\s*\(/', ['Modules/Erasure/Claim/ErasureClaimer.php']))->toBe([]);
});

it('lets only the command and the reapply call the run\'s execute: no other app code references the run at all', function (): void {
    $allowed = [
        'Modules/Erasure/Run/ErasureRun.php',
        'Modules/Erasure/Run/ErasureReapply.php',
        'Modules/Erasure/Console/ErasureRunCommand.php',
        'Modules/Erasure/ErasureServiceProvider.php',
        'Modules/Erasure/Cancellation/CancelOpenAssignments.php',
    ];

    // Code, not prose: an import, a class constant or a type-hinted parameter.
    expect(claimGuardOffenders('/Erasure\\Run\\ErasureRun;|ErasureRun::class|\bErasureRun\s+\$/', $allowed))->toBe([]);
});

it('lets only the claimer write the claim stamp', function (): void {
    // A WRITE is `'erasure_started_at' =>` or `->erasure_started_at =`. The model's cast table is the
    // one other place the quoted name is followed by an arrow; the factory's state is test-only.
    $writes = claimGuardOffenders('/[\'"]erasure_started_at[\'"]\s*=>|->erasure_started_at\s*=[^=]/', [
        'Modules/Erasure/Claim/ErasureClaimer.php',
        'Modules/Identity/Database/Factories/UserFactory.php',
        'Modules/Identity/Models/User.php',
    ]);

    expect($writes)->toBe([]);
});

it('keeps the engine\'s own claim re-read in place', function (): void {
    $source = claimGuardSources()['Modules/Erasure/Run/ErasureRun.php'];

    expect($source)->toContain('ErasureClaim $claim')->toContain("whereNotNull('erasure_started_at')");
});

it('takes the user row lock in every method that stamps or clears the claim (sqlite cannot show a lock, so the source is pinned)', function (): void {
    $source = claimGuardSources()['Modules/Erasure/Claim/ErasureClaimer.php'];

    // claim(), reclaim() and release() each lock the row they are about to stamp or clear.
    expect(substr_count($source, '->lockForUpdate()->firstOrFail()'))->toBe(3);
    // ...and each does it inside a transaction: a refusal after a stamp must roll the stamp back.
    expect(substr_count($source, 'return $this->db->transaction(function ()'))->toBe(3);
});
