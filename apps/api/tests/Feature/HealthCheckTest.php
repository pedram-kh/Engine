<?php

declare(strict_types=1);
use Tests\TestCase;

uses(TestCase::class);

it('responds to /health with status ok when the runtime can accept the upload cap', function () {
    // Force the app cap below the test runtime's PHP ceiling so the
    // uploads check is satisfied regardless of the CI box's php.ini.
    config(['uploads.avatar_max_bytes' => 1024]);

    $this->getJson('/health')
        ->assertOk()
        ->assertJson(['status' => 'ok'])
        ->assertJsonPath('checks.uploads.status', 'ok');
});

it('reports degraded uploads (precise reason) when the PHP ceiling is below the app cap', function () {
    // 1 GiB cap is guaranteed to exceed any sane test runtime ceiling.
    config(['uploads.avatar_max_bytes' => 1024 * 1024 * 1024]);

    $this->getJson('/health')
        // Liveness stays 200 — a config issue must not trigger a restart loop.
        ->assertOk()
        ->assertJson(['status' => 'degraded'])
        ->assertJsonPath('checks.uploads.status', 'degraded')
        ->assertJsonPath('checks.uploads.required_bytes', 1024 * 1024 * 1024);

    $detail = $this->getJson('/health')->json('checks.uploads.detail');
    expect($detail)->toContain('upload_max_filesize');
});
