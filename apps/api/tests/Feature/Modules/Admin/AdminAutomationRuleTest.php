<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AutomationAction;
use App\Modules\Admin\Enums\AutomationEvent;
use App\Modules\Admin\Models\AdminAutomationRule;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-090 (D6) — the operator's rule CRUD.
|--------------------------------------------------------------------------
|
| Nothing here can fire anything: creating a rule is configuration, and the
| platform_automations_enabled flag (the feature-flags surface) is the arming
| switch. What this file pins is that every write is platform-admin-gated,
| reason-mandatory, audited with a full snapshot, and that a rule cannot be
| stored pointing at an agency the connect path would refuse.
*/

const AUTO_RULES_URL = '/api/v1/admin/automation-rules';

function ruleAdmin(): User
{
    return User::factory()->create([
        'type' => UserType::PlatformAdmin,
        'two_factor_confirmed_at' => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function rulePayload(Agency $agency, string $name = 'Approved → Northwind'): array
{
    return [
        'name' => $name,
        'event' => AutomationEvent::CreatorApproved->value,
        'action' => AutomationAction::ConnectToAgency->value,
        'config' => ['agency_id' => $agency->ulid],
        'reason' => 'Northwind signed a platform-wide representation deal.',
    ];
}

// ---------------------------------------------------------------------------
// Authorization — every verb, no exceptions
// ---------------------------------------------------------------------------

it('401s every route unauthenticated', function (): void {
    $rule = AdminAutomationRule::factory()->createOne();

    expect($this->getJson(AUTO_RULES_URL)->status())->toBe(401)
        ->and($this->postJson(AUTO_RULES_URL, [])->status())->toBe(401)
        ->and($this->patchJson(AUTO_RULES_URL."/{$rule->ulid}", [])->status())->toBe(401)
        ->and($this->deleteJson(AUTO_RULES_URL."/{$rule->ulid}", [])->status())->toBe(401)
        ->and($this->postJson(AUTO_RULES_URL."/{$rule->ulid}/toggle", [])->status())->toBe(401);
});

it('403s every route for a non-platform-admin', function (): void {
    $agencyUser = User::factory()->create([
        'type' => UserType::AgencyUser,
        'two_factor_confirmed_at' => now(),
    ]);
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();

    $this->actingAs($agencyUser, 'web_admin');

    expect($this->getJson(AUTO_RULES_URL)->status())->toBe(403)
        ->and($this->postJson(AUTO_RULES_URL, rulePayload($agency))->status())->toBe(403)
        ->and($this->patchJson(AUTO_RULES_URL."/{$rule->ulid}", rulePayload($agency))->status())->toBe(403)
        ->and($this->deleteJson(AUTO_RULES_URL."/{$rule->ulid}", ['reason' => 'Trying to delete as a non-admin.'])->status())->toBe(403)
        ->and($this->postJson(AUTO_RULES_URL."/{$rule->ulid}/toggle", ['enabled' => false, 'reason' => 'Trying to toggle as a non-admin.'])->status())->toBe(403);

    // Nothing leaked through the 403s.
    expect(AdminAutomationRule::query()->count())->toBe(1)
        ->and($rule->refresh()->enabled)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Create
// ---------------------------------------------------------------------------

it('creates a rule, enabled by default, and audits it with a full snapshot', function (): void {
    $admin = ruleAdmin();
    $agency = Agency::factory()->createOne(['name' => 'Northwind Talent']);

    $response = $this->actingAs($admin, 'web_admin')->postJson(AUTO_RULES_URL, rulePayload($agency));

    $response->assertCreated()
        ->assertJsonPath('data.attributes.name', 'Approved → Northwind')
        ->assertJsonPath('data.attributes.event', 'creator.approved')
        ->assertJsonPath('data.attributes.action', 'connect_to_agency')
        ->assertJsonPath('data.attributes.enabled', true)
        ->assertJsonPath('data.attributes.target_agency_id', $agency->ulid)
        ->assertJsonPath('data.attributes.target_agency_name', 'Northwind Talent')
        ->assertJsonPath('data.attributes.broken_reason', null)
        ->assertJsonPath('data.attributes.fire_count', 0)
        ->assertJsonPath('data.attributes.last_fired_at', null);

    $rule = AdminAutomationRule::query()->firstOrFail();
    expect($rule->config)->toBe(['agency_id' => $agency->ulid]);

    $audit = AuditLog::query()->where('action', AuditAction::AutomationRuleCreated->value)->firstOrFail();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->reason)->toBe('Northwind signed a platform-wide representation deal.')
        ->and($audit->after)->toMatchArray([
            'name' => 'Approved → Northwind',
            'event' => 'creator.approved',
            'enabled' => true,
        ]);
});

it('can create a rule that starts DISABLED', function (): void {
    $agency = Agency::factory()->createOne();

    $this->actingAs(ruleAdmin(), 'web_admin')
        ->postJson(AUTO_RULES_URL, [...rulePayload($agency), 'enabled' => false])
        ->assertCreated()
        ->assertJsonPath('data.attributes.enabled', false);
});

it('422s a create with no reason — the verb requiresReason', function (): void {
    $agency = Agency::factory()->createOne();
    $payload = rulePayload($agency);
    unset($payload['reason']);

    $this->actingAs(ruleAdmin(), 'web_admin')->postJson(AUTO_RULES_URL, $payload)->assertStatus(422);

    // The reason is refused at the boundary, so nothing was written and
    // AuditLogger never had the chance to throw mid-transaction.
    expect(AdminAutomationRule::query()->count())->toBe(0);
});

it('422s an unknown event or action', function (string $field, string $value): void {
    $agency = Agency::factory()->createOne();

    $this->actingAs(ruleAdmin(), 'web_admin')
        ->postJson(AUTO_RULES_URL, [...rulePayload($agency), $field => $value])
        ->assertEnvelopeValidationErrors([$field]);

    expect(AdminAutomationRule::query()->count())->toBe(0);
})->with([
    ['event', 'creator.rejected'],
    ['action', 'send_carrier_pigeon'],
]);

it('422s a config naming an agency that does not exist', function (): void {
    $this->actingAs(ruleAdmin(), 'web_admin')
        ->postJson(AUTO_RULES_URL, [
            'name' => 'Approved → nowhere',
            'event' => AutomationEvent::CreatorApproved->value,
            'action' => AutomationAction::ConnectToAgency->value,
            'config' => ['agency_id' => 'not-a-real-ulid'],
            'reason' => 'Pointing a rule at an agency that is not there.',
        ])
        ->assertEnvelopeValidationErrors(['config.agency_id']);
});

it('422s a config naming a SUSPENDED agency (D3, at configuration time)', function (): void {
    $agency = Agency::factory()->suspended()->createOne();

    $this->actingAs(ruleAdmin(), 'web_admin')
        ->postJson(AUTO_RULES_URL, rulePayload($agency))
        ->assertEnvelopeValidationErrors(['config.agency_id']);

    // The cheap half of the guard. A rule can still DRIFT into this state after
    // creation, which is why the job re-checks and the list renders it.
    expect(AdminAutomationRule::query()->count())->toBe(0);
});

it('stores only the keys the action declares', function (): void {
    $agency = Agency::factory()->createOne();

    $this->actingAs(ruleAdmin(), 'web_admin')
        ->postJson(AUTO_RULES_URL, [
            ...rulePayload($agency),
            'config' => ['agency_id' => $agency->ulid, 'smuggled' => 'nothing reads this'],
        ])
        ->assertCreated();

    // A JSON column no validator reads again is not a place to let arbitrary
    // keys accumulate.
    expect(AdminAutomationRule::query()->firstOrFail()->config)->toBe(['agency_id' => $agency->ulid]);
});

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------

it('lists rules newest-first with their fire trace', function (): void {
    $agency = Agency::factory()->createOne(['name' => 'Northwind']);
    AdminAutomationRule::factory()->connectTo($agency)->fired(7)->createOne(['name' => 'Older rule']);
    AdminAutomationRule::factory()->connectTo($agency)->createOne(['name' => 'Newer rule']);

    $response = $this->actingAs(ruleAdmin(), 'web_admin')->getJson(AUTO_RULES_URL);

    $response->assertOk()
        ->assertJsonPath('data.0.attributes.name', 'Newer rule')
        ->assertJsonPath('data.1.attributes.name', 'Older rule')
        ->assertJsonPath('data.1.attributes.fire_count', 7);

    expect($response->json('data.1.attributes.last_fired_at'))->not->toBeNull();
});

it('renders a BROKEN rule rather than hiding it', function (): void {
    $suspended = Agency::factory()->suspended()->createOne(['name' => 'Suspended Agency']);
    AdminAutomationRule::factory()->connectTo($suspended)->createOne(['name' => 'Points at suspended']);
    AdminAutomationRule::factory()->connectToMissingAgency()->createOne(['name' => 'Points at nothing']);
    AdminAutomationRule::factory()->createOne(['name' => 'Names no agency']);

    $response = $this->actingAs(ruleAdmin(), 'web_admin')->getJson(AUTO_RULES_URL);

    $response->assertOk();

    /** @var array<int, array{attributes: array<string, mixed>}> $rows */
    $rows = $response->json('data');

    /** @var array<string, array<string, mixed>> $byName */
    $byName = [];
    foreach ($rows as $row) {
        $byName[(string) $row['attributes']['name']] = $row['attributes'];
    }

    /*
     * The config holds a ULID with no FK behind it, so a rule can be
     * broken-but-present at any time. The job already refuses to fire; this is
     * what stops an operator staring at a rule that silently never does anything.
     * A suspended target is still NAMED — you cannot fix what you cannot see.
     */
    expect($byName['Points at suspended']['broken_reason'])->toBe('agency_suspended')
        ->and($byName['Points at suspended']['target_agency_name'])->toBe('Suspended Agency')
        ->and($byName['Points at suspended']['target_agency_suspended'])->toBeTrue()
        ->and($byName['Points at nothing']['broken_reason'])->toBe('agency_missing')
        ->and($byName['Points at nothing']['target_agency_name'])->toBeNull()
        ->and($byName['Names no agency']['broken_reason'])->toBe('misconfigured');
});

it('is unpaginated — 30 rules are all present', function (): void {
    $agency = Agency::factory()->createOne();
    AdminAutomationRule::factory()->connectTo($agency)->count(30)->create();

    $response = $this->actingAs(ruleAdmin(), 'web_admin')->getJson(AUTO_RULES_URL);

    // Operator-created, tens of rows: the whole set travels. A page-2 rule an
    // operator cannot see is a rule they cannot turn off.
    $response->assertOk()->assertJsonCount(30, 'data');
});

// ---------------------------------------------------------------------------
// Update / toggle / delete
// ---------------------------------------------------------------------------

it('updates a rule and audits before + after', function (): void {
    $admin = ruleAdmin();
    $northwind = Agency::factory()->createOne(['name' => 'Northwind']);
    $eastwind = Agency::factory()->createOne(['name' => 'Eastwind']);
    $rule = AdminAutomationRule::factory()->connectTo($northwind)->createOne(['name' => 'Original name']);

    $this->actingAs($admin, 'web_admin')
        ->patchJson(AUTO_RULES_URL."/{$rule->ulid}", [
            'name' => 'Retargeted rule',
            'event' => AutomationEvent::CreatorApproved->value,
            'action' => AutomationAction::ConnectToAgency->value,
            'config' => ['agency_id' => $eastwind->ulid],
            'reason' => 'The deal moved from Northwind to Eastwind.',
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.name', 'Retargeted rule')
        ->assertJsonPath('data.attributes.target_agency_name', 'Eastwind');

    $audit = AuditLog::query()->where('action', AuditAction::AutomationRuleUpdated->value)->firstOrFail();
    expect($audit->before['name'] ?? null)->toBe('Original name')
        ->and($audit->before['config']['agency_id'] ?? null)->toBe($northwind->ulid)
        ->and($audit->after['config']['agency_id'] ?? null)->toBe($eastwind->ulid)
        ->and($audit->reason)->toBe('The deal moved from Northwind to Eastwind.');
});

it('toggles a rule in BOTH directions, each with a mandatory reason', function (): void {
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();

    $this->actingAs(ruleAdmin(), 'web_admin')
        ->postJson(AUTO_RULES_URL."/{$rule->ulid}/toggle", [
            'enabled' => false,
            'reason' => 'Pausing this rule while we verify the roster.',
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.enabled', false);

    expect($rule->refresh()->enabled)->toBeFalse();

    $this->actingAs(ruleAdmin(), 'web_admin')
        ->postJson(AUTO_RULES_URL."/{$rule->ulid}/toggle", [
            'enabled' => true,
            'reason' => 'Roster verified; re-arming the rule.',
        ])
        ->assertOk()
        ->assertJsonPath('data.attributes.enabled', true);

    // Divergence from agency.reactivated (where restoring the safe state needs
    // no reason) is deliberate: arming a rule that writes relationship rows is
    // not "restoring a default".
    expect(AuditLog::query()->where('action', AuditAction::AutomationRuleToggled->value)->count())->toBe(2);
});

it('422s a toggle with no reason and does not flip', function (): void {
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();

    $this->actingAs(ruleAdmin(), 'web_admin')
        ->postJson(AUTO_RULES_URL."/{$rule->ulid}/toggle", ['enabled' => false])
        ->assertStatus(422);

    expect($rule->refresh()->enabled)->toBeTrue();
});

it('hard-deletes a rule, keeping the full row in the audit before-snapshot', function (): void {
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->fired(4)->createOne(['name' => 'Retired rule']);

    $this->actingAs(ruleAdmin(), 'web_admin')
        ->deleteJson(AUTO_RULES_URL."/{$rule->ulid}", [
            'reason' => 'The representation deal ended last quarter.',
        ])
        ->assertNoContent();

    expect(AdminAutomationRule::query()->count())->toBe(0);

    // Hard delete is only honest if the record survives elsewhere: "what did
    // that rule do before someone removed it" has to stay answerable.
    $audit = AuditLog::query()->where('action', AuditAction::AutomationRuleDeleted->value)->firstOrFail();
    expect($audit->before)->toMatchArray([
        'name' => 'Retired rule',
        'fire_count' => 4,
        'config' => ['agency_id' => $agency->ulid],
    ])
        ->and($audit->reason)->toBe('The representation deal ended last quarter.');
});

it('422s a delete with no reason and keeps the rule', function (): void {
    $agency = Agency::factory()->createOne();
    $rule = AdminAutomationRule::factory()->connectTo($agency)->createOne();

    $this->actingAs(ruleAdmin(), 'web_admin')
        ->deleteJson(AUTO_RULES_URL."/{$rule->ulid}", [])
        ->assertStatus(422);

    expect(AdminAutomationRule::query()->count())->toBe(1);
});

it('404s an unknown rule ulid', function (): void {
    $this->actingAs(ruleAdmin(), 'web_admin')
        ->postJson(AUTO_RULES_URL.'/01JXXXXXXXXXXXXXXXXXXXXXXX/toggle', [
            'enabled' => false,
            'reason' => 'Toggling a rule that does not exist.',
        ])
        ->assertStatus(404);
});
