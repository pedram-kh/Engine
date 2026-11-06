<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once dirname(__DIR__, 3).'/Support/MessageInboxFixtures.php';

/** AH-119 ruling Q2: system lines never surface a thread and never count as unread. */
it('does not surface a thread that holds only system lines', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    inboxAssignment($agency, humans: 0, system: 3);
    $real = inboxAssignment($agency, humans: 1);

    $res = $this->actingAs($admin)->getJson(inboxUrl($agency))->assertOk();

    expect(inboxList($res, 'data')->pluck('id')->all())->toBe([$real['thread']->ulid])
        ->and($res->json('meta.unread_threads_total'))->toBe(1);
});

it('adds nothing to the badge, the pill or a facet for system lines on a thread that has a human message', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $asg = inboxAssignment($agency, humans: 1, system: 4);

    $res = $this->actingAs($admin)->getJson(inboxUrl($agency))->assertOk();

    expect($res->json('data.0.attributes.unread_count'))->toBe(1)
        ->and($res->json('meta.unread_threads_total'))->toBe(1)
        ->and($res->json('meta.facets.brands.0.unread_threads'))->toBe(1);

    inboxMarkRead($admin, $asg['messages'][0]);

    $after = $this->actingAs($admin)->getJson(inboxUrl($agency))->assertOk();
    expect($after->json('data.0.attributes.unread_count'))->toBe(0)
        ->and($after->json('meta.unread_threads_total'))->toBe(0);
});

it('previews the last HUMAN message, never a system line, even when a system line is newer', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    inboxAssignment($agency, humans: 1, system: 2);

    expect($this->actingAs($admin)->getJson(inboxUrl($agency))->json('data.0.attributes.preview'))->toBe('assignment 0');
});

it('does not surface a thread whose only human message was deleted', function (): void {
    ['agency' => $agency, 'admin' => $admin] = inboxWorld();
    $asg = inboxAssignment($agency, humans: 1);
    $asg['messages'][0]->delete();
    $direct = inboxDirect($agency, inboxCreator());
    $direct['messages'][0]->delete();

    $this->actingAs($admin)->getJson(inboxUrl($agency))->assertOk()->assertJsonCount(0, 'data');
});
