<?php

declare(strict_types=1);

use App\Modules\Creators\Http\Controllers\BulkInviteController;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Standing standard #40 — defense-in-depth coverage for authorizeAdmin()
|--------------------------------------------------------------------------
|
| The bulk-invite endpoint enforces agency_admin role via an inline
| authorizeAdmin() method (D-pause-9 — mirrors Sprint 2
| InvitationController::authorizeAdmin()).
|
| This unit test inspects the controller's source AND uses reflection
| to confirm the private method exists. Removing the method (or its
| call from store()) makes the regression test fail before any
| feature-test exercises the role check.
|
| Break-revert verification per #40:
|   1. Comment out the `$this->authorizeAdmin(...)` line in store();
|   2. Run this test → fails on the source-string assertion;
|   3. Run BulkInviteEndpointTest::"refuses non-admin users" → fails on 403.
|   4. Restore the line; both pass.
*/

it('BulkInviteController defines a private authorizeAdmin() method', function (): void {
    $reflection = new ReflectionClass(BulkInviteController::class);

    expect($reflection->hasMethod('authorizeAdmin'))->toBeTrue('controller must define authorizeAdmin()');

    $method = $reflection->getMethod('authorizeAdmin');
    expect($method->isPrivate())->toBeTrue('authorizeAdmin() must be private (in-controller pattern)');
});

it('BulkInviteController::store() invokes authorizeAdmin() before any other work', function (): void {
    $reflection = new ReflectionClass(BulkInviteController::class);
    $method = $reflection->getMethod('store');

    $filename = $method->getFileName();
    $start = $method->getStartLine();
    $end = $method->getEndLine();

    expect($filename)->toBeString();
    /** @var string $filename */
    $source = file($filename, FILE_IGNORE_NEW_LINES);
    expect($source)->not->toBeFalse();
    /** @var list<string> $source */
    $body = implode("\n", array_slice($source, $start - 1, $end - $start + 1));

    expect($body)->toContain('$this->authorizeAdmin($request, $agency);')
        ->toMatch('/store\([^)]*\).*\{[\s\n]*\$this->authorizeAdmin/s');
});

it('authorizeAdmin() role-check guards against non-AgencyAdmin (regression: lifts the role string)', function (): void {
    $reflection = new ReflectionClass(BulkInviteController::class);
    $method = $reflection->getMethod('authorizeAdmin');
    $start = $method->getStartLine();
    $end = $method->getEndLine();
    $filename = $method->getFileName();
    expect($filename)->toBeString();
    /** @var string $filename */
    $source = file($filename, FILE_IGNORE_NEW_LINES);
    expect($source)->not->toBeFalse();
    /** @var list<string> $source */
    $body = implode("\n", array_slice($source, $start - 1, $end - $start + 1));

    expect($body)
        ->toContain('AgencyRole::AgencyAdmin')
        ->toContain('abort(403');
});
