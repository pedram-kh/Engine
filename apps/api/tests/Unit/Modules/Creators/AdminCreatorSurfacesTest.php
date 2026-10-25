<?php

declare(strict_types=1);

use Tests\Support\AdminCreatorSurfaces;
use Tests\Support\AgencyCreatorSurfaces;

/**
 * AH-104c C2-4 — the static half of "an admin screen knows an account is leaving".
 *
 * Heuristic and honest about it (see AdminCreatorSurfaces). Plus the negative half: nothing an
 * agency can read may carry `deletion_pending` (a cancelled request must leave no trace there).
 */
function adminSurfacesRoot(): string
{
    return dirname(__DIR__, 4).'/app/Modules';
}

/** @return list<string> */
function adminCreatorReadingFiles(): array
{
    $found = [];
    foreach (AdminCreatorSurfaces::DIRECTORIES as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(adminSurfacesRoot().'/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && preg_match(AdminCreatorSurfaces::READS_CREATORS, (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = ltrim(str_replace(adminSurfacesRoot(), '', $file->getPathname()), '/');
            }
        }
    }
    sort($found);

    return $found;
}

it('lists every admin file that reads creators in the manifest (a new one must be classified)', function (): void {
    $unlisted = array_values(array_diff(adminCreatorReadingFiles(), array_keys(AdminCreatorSurfaces::all())));

    expect($unlisted)->toBe([], 'Classify these in tests/Support/AdminCreatorSurfaces.php: '.implode(', ', $unlisted));
});

it('has no stale manifest entry (every listed file exists, and the scanned ones still read creators)', function (): void {
    $stale = [];
    foreach (array_keys(AdminCreatorSurfaces::all()) as $path) {
        $full = adminSurfacesRoot().'/'.$path;
        if (! is_file($full)) {
            $stale[] = $path;

            continue;
        }
        $scanned = ! in_array($path, AdminCreatorSurfaces::EXTRA, true);
        if ($scanned && preg_match(AdminCreatorSurfaces::READS_CREATORS, (string) file_get_contents($full)) !== 1) {
            $stale[] = $path;
        }
    }

    expect($stale)->toBe([]);
});

it('requires the marker each kind promises, a real reason, and a tech-debt entry for an open gap', function (): void {
    $problems = [];
    foreach (AdminCreatorSurfaces::all() as $path => [$kind, $reason]) {
        if (mb_strlen(trim($reason)) < 12) {
            $problems[] = "{$path}: a reason is required";
        }
        if ($kind === 'open' && ! str_contains($reason, 'tech-debt AH-')) {
            $problems[] = "{$path}: an open gap must name its tech-debt entry";
        }
        $markers = AdminCreatorSurfaces::markers($kind);
        if ($markers === null) {
            continue;
        }
        $source = (string) file_get_contents(adminSurfacesRoot().'/'.$path);
        if (array_filter($markers, static fn (string $m): bool => str_contains($source, $m)) === []) {
            $problems[] = "{$path}: kind '{$kind}' requires one of [".implode(', ', $markers).']';
        }
    }

    expect($problems)->toBe([]);
});

it('knows only the kinds it documents', function (): void {
    $kinds = array_unique(array_map(static fn (array $e): string => $e[0], AdminCreatorSurfaces::all()));
    sort($kinds);

    expect($kinds)->toBe(['flagged', 'open', 'reader', 'unchanged']);
});

it('names every open gap in docs/tech-debt.md', function (): void {
    $debt = (string) file_get_contents(dirname(__DIR__, 6).'/docs/tech-debt.md');
    $missing = [];
    foreach (AdminCreatorSurfaces::all() as $path => [$kind, $reason]) {
        if ($kind === 'open' && preg_match('/tech-debt (AH-[0-9A-Za-z ]+C2-4a)/', $reason, $m) === 1 && ! str_contains($debt, $m[1])) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([]);
});

it('never lets an agency-facing module name the admin flags (a cancelled request leaves no trace)', function (): void {
    $offenders = [];
    foreach (AgencyCreatorSurfaces::MODULES as $module) {
        $dir = adminSurfacesRoot().'/'.$module;
        if (! is_dir($dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'deletion_pending') || str_contains($source, 'AdminDeletionFlags')) {
                $offenders[] = ltrim(str_replace(adminSurfacesRoot(), '', $file->getPathname()), '/');
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the creator-facing view of a creator free of the admin flags', function (): void {
    $source = (string) file_get_contents(adminSurfacesRoot().'/Creators/Http/Resources/CreatorResource.php');
    // Everything before the first use of the flags: the admin-view guard must be in it.
    $beforeFlags = explode('AdminDeletionFlags::forCreator', $source, 2)[0];

    expect($source)->toContain('AdminDeletionFlags::forCreator')
        ->and($beforeFlags)->toContain("if (\$this->isAdminView) {\n            \$payload['admin_attributes']");
});
