<?php

declare(strict_types=1);

use Tests\Support\AgencyCreatorSurfaces;

/**
 * AH-104b-del (D3) — the static half of "a creator who asked to delete their
 * account (or was erased) is handled on every agency-facing surface".
 *
 * Heuristic and honest about it: it greps agency-facing modules for files that
 * read creators, and fails on any that the manifest does not list, so the next
 * file that reads creators has to be classified in the same commit. The
 * behavioural matrix (CreatorUnavailableToAgenciesTest) is the strong half.
 */
function surfacesRoot(): string
{
    return dirname(__DIR__, 4).'/app/Modules';
}

/** @return list<string> */
function creatorReadingFiles(): array
{
    $found = [];
    foreach (AgencyCreatorSurfaces::MODULES as $module) {
        $dir = surfacesRoot().'/'.$module;
        if (! is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match(AgencyCreatorSurfaces::READS_CREATORS, (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = ltrim(str_replace(surfacesRoot(), '', $file->getPathname()), '/');
            }
        }
    }
    sort($found);

    return $found;
}

it('lists every agency-facing file that reads creators in the manifest (a new one must be classified)', function (): void {
    $unlisted = array_values(array_diff(creatorReadingFiles(), array_keys(AgencyCreatorSurfaces::all())));

    expect($unlisted)->toBe([], 'Classify these in tests/Support/AgencyCreatorSurfaces.php: '.implode(', ', $unlisted));
});

it('has no stale manifest entry (every listed file exists and still reads creators)', function (): void {
    $stale = [];
    foreach (array_keys(AgencyCreatorSurfaces::all()) as $path) {
        $full = surfacesRoot().'/'.$path;
        if (! is_file($full) || preg_match(AgencyCreatorSurfaces::READS_CREATORS, (string) file_get_contents($full)) !== 1) {
            $stale[] = $path;
        }
    }

    expect($stale)->toBe([]);
});

it('requires the marker each kind promises, and a real reason for every kind that has none', function (): void {
    $problems = [];
    foreach (AgencyCreatorSurfaces::all() as $path => [$kind, $reason]) {
        if (mb_strlen(trim($reason)) < 12) {
            $problems[] = "{$path}: a reason is required";
        }
        $markers = AgencyCreatorSurfaces::markers($kind);
        if ($markers === null) {
            continue;
        }
        $source = (string) file_get_contents(surfacesRoot().'/'.$path);
        $hit = array_filter($markers, static fn (string $m): bool => str_contains($source, $m));
        if ($hit === []) {
            $problems[] = "{$path}: kind '{$kind}' requires one of [".implode(', ', $markers).']';
        }
    }

    expect($problems)->toBe([]);
});

it('knows only the kinds it documents', function (): void {
    $kinds = array_unique(array_map(static fn (array $e): string => $e[0], AgencyCreatorSurfaces::all()));
    sort($kinds);

    expect($kinds)->toBe(['creator-side', 'flagged', 'guarded', 'model', 'resource', 'scoped', 'silent', 'unchanged', 'via-relation']);
});

it('requires is_erased in every resource of the manifest', function (): void {
    $missing = [];
    foreach (AgencyCreatorSurfaces::all() as $path => [$kind]) {
        if ($kind === 'resource' && ! str_contains((string) file_get_contents(surfacesRoot().'/'.$path), "'is_erased'")) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([]);
});
