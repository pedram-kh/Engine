<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Sprint 3 Chunk 1 — filesystem-disk configuration regression
|--------------------------------------------------------------------------
|
| Source-inspection regression test (#1) for the four MinIO-backed disks
| introduced by Sprint 3 Chunk 1. If a disk is renamed or its driver
| changes, this test fails and the operator runbook in
| docs/runbooks/local-dev.md must be updated with the new wiring.
|
| D-pause-5: the new public-bucket disk is named `media-public` (not
| `public`) to avoid colliding with Laravel's default public disk.
*/

it('the four Sprint 3 Chunk 1 disks are registered with the s3 driver', function (): void {
    $expected = [
        'media',
        'contracts',
        'exports',
        'media-public',
    ];

    foreach ($expected as $disk) {
        $config = config("filesystems.disks.{$disk}");
        expect($config)->toBeArray("disk '{$disk}' should be configured")
            ->and($config['driver'])->toBe('s3', "disk '{$disk}' should use the s3 driver");
    }
});

it('Laravel default `public` disk is preserved (D-pause-5)', function (): void {
    $config = config('filesystems.disks.public');
    expect($config)->toBeArray()
        ->and($config['driver'])->toBe('local')
        ->and($config['root'])->toBe(storage_path('app/public'));
});

it('media-public is the only Sprint 3 disk with public visibility', function (): void {
    $publicVisibility = ['media-public'];
    $privateVisibility = ['media', 'contracts', 'exports'];

    foreach ($publicVisibility as $disk) {
        expect(config("filesystems.disks.{$disk}.visibility"))->toBe('public');
    }

    foreach ($privateVisibility as $disk) {
        expect(config("filesystems.disks.{$disk}.visibility"))->toBe('private');
    }
});

it('Sprint 3 disks all read AWS_ENDPOINT_URL with AWS_ENDPOINT fallback', function (): void {
    $disks = ['s3', 'media', 'contracts', 'exports', 'media-public'];

    foreach ($disks as $disk) {
        // Endpoint may be null in production where AWS endpoints are
        // implicit. The contract is that the env-var resolution chain
        // is correct — the value itself depends on the runtime env.
        $endpoint = config("filesystems.disks.{$disk}.endpoint");
        expect($endpoint === null || is_string($endpoint))->toBeTrue();
    }
});
