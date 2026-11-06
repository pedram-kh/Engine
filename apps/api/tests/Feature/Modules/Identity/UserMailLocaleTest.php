<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * AH-119 A0 F1. users.preferred_language is char(2): Postgres pads an empty value to two spaces,
 * which is truthy, so a bare `?: 'en'` never fell back. mailLocale() trims first.
 */

it('falls back to en for an empty, blank or padded stored language, and keeps a real one', function (string $stored, string $expected): void {
    $user = User::factory()->create(['preferred_language' => $stored]);

    // Read back from the database: this is the value a mailer sees (padded on Postgres).
    expect(User::query()->findOrFail($user->id)->mailLocale())->toBe($expected);
})->with([
    'empty' => ['', 'en'],
    'blank' => ['  ', 'en'],
    'portuguese' => ['pt', 'pt'],
]);

it('is the only way mail locale is derived: no `preferred_language ?: \'en\'` left under app/', function (): void {
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')
            && str_contains((string) file_get_contents($file->getPathname()), "preferred_language ?: 'en'")) {
            $found[] = $file->getPathname();
        }
    }

    expect($found)->toBe([]);
});
