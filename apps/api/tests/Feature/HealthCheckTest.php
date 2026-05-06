<?php

declare(strict_types=1);
use Tests\TestCase;

uses(TestCase::class);

it('responds to /health with status ok', function () {
    $response = $this->getJson('/health');

    $response->assertOk()
        ->assertJson(['status' => 'ok']);
});
