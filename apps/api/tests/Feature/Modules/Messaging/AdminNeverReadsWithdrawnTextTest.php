<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

/*
 * AH-119 B3/B4 — `original_body` and `deleted_body` are withdrawn text. Nothing in the platform-admin
 * module may read them (the admin surfaces are Tier-1 lookups that never show message text at all),
 * and no JSON resource anywhere may name them.
 */

function phpFilesUnder(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }

    return $out;
}

it('has no admin-module code that names the withdrawn-text columns', function (): void {
    $files = phpFilesUnder(app_path('Modules/Admin'));
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        expect($source)->not->toContain('original_body', $file)->and($source)->not->toContain('deleted_body', $file);
    }
});

it('has no HTTP resource or controller outside the revision service naming them for output', function (): void {
    $allowed = [
        'Services/MessageRevisionService.php',
        'Models/Message.php',
        'Models/RelationshipMessage.php',
    ];
    foreach (phpFilesUnder(app_path('Modules/Messaging')) as $file) {
        $relative = str_replace(app_path('Modules/Messaging').'/', '', $file);
        if (in_array($relative, $allowed, true) || str_contains($file, '/Database/')) {
            continue;
        }
        $source = (string) file_get_contents($file);
        expect($source)->not->toContain('original_body', $relative)->and($source)->not->toContain('deleted_body', $relative);
    }
});

it('never selects them in the search service, the inbox query or the resources', function (): void {
    foreach (['Services/MessageSearch.php', 'Services/MessageInboxQuery.php', 'Http/Resources/MessageResource.php', 'Http/Resources/RelationshipMessageResource.php'] as $relative) {
        $source = (string) file_get_contents(app_path('Modules/Messaging/'.$relative));
        expect($source)->not->toContain('original_body', $relative)->and($source)->not->toContain('deleted_body', $relative);
    }
});
