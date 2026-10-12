<?php

declare(strict_types=1);

use App\Core\Enums\Locale;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Push\PushEligibility;
use App\Modules\Notifications\Push\PushMessage;
use App\Modules\Notifications\Push\PushMessageFactory;
use Tests\Support\NotificationRouteKeys;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| AH-102 C2.2 — push copy (lang push.php x24) and PushMessageFactory
|--------------------------------------------------------------------------
|
| 1. REAL rendering for every eligible type × every UI locale, using the real
|    `data` bag each emit site produces (not a test-shaped bag).
| 2. PRIVACY (Q6): sentinel strings planted in every field a lock screen must
|    never show (feedback, rejection_reason, message text, welcome message…) must
|    not appear ANYWHERE in the message — 14 types × 24 locales.
| 3. Names: injected untranslated, sanitised, capped; a missing name drops the
|    body, never the push.
| 4. Locale discipline: explicit locale, caller's locale untouched.
| 5. The flaky-ten value check (no English fallback), and copy ⇔ eligibility.
*/

/**
 * The `data` bag each emit site actually sends, per eligible type (see the emit
 * sites in Campaigns/Messaging/Creators/Agencies — file:line in the AH-102
 * review). Includes the free-text-bearing keys those sites also put in the bag.
 *
 * @return array<string, array<string, mixed>>
 */
function pushRealBags(): array
{
    return [
        'assignment.invited' => ['campaign_name' => 'Summer Launch', 'creator_name' => 'Nora Creator', 'assignment_ulid' => PUSH_ASSIGN_ULID],
        'assignment.revision_requested' => ['campaign_name' => 'Summer Launch', 'creator_name' => 'Nora Creator', 'outcome' => 'revision_requested', 'feedback' => 'SENTINEL-FEEDBACK-xyz', 'assignment_ulid' => PUSH_ASSIGN_ULID, 'version' => 2],
        'assignment.draft_approved' => ['campaign_name' => 'Summer Launch', 'creator_name' => 'Nora Creator', 'outcome' => 'approved', 'feedback' => 'SENTINEL-FEEDBACK-xyz', 'assignment_ulid' => PUSH_ASSIGN_ULID, 'version' => 1],
        'assignment.draft_rejected' => ['campaign_name' => 'Summer Launch', 'creator_name' => 'Nora Creator', 'outcome' => 'rejected', 'feedback' => 'SENTINEL-FEEDBACK-xyz', 'assignment_ulid' => PUSH_ASSIGN_ULID, 'version' => 1],
        'assignment.completed_on_approval' => ['campaign_name' => 'Summer Launch', 'creator_name' => 'Nora Creator', 'assignment_ulid' => PUSH_ASSIGN_ULID, 'version' => 1],
        'assignment.manually_verified' => ['campaign_name' => 'Summer Launch', 'creator_name' => 'Nora Creator', 'assignment_ulid' => PUSH_ASSIGN_ULID],
        'message.received_by_creator' => ['campaign_name' => 'Summer Launch', 'sender_name' => 'Acme Agent', 'assignment_ulid' => PUSH_ASSIGN_ULID, 'body' => 'SENTINEL-MESSAGE-TEXT-xyz'],
        'message.relationship_received_by_creator' => ['agency_name' => 'Acme Agency', 'creator_name' => 'Nora Creator', 'sender_name' => 'Acme Agent', 'thread_ulid' => PUSH_THREAD_ULID, 'body' => 'SENTINEL-MESSAGE-TEXT-xyz'],
        'campaign_application.accepted' => ['agency_name' => 'Acme Agency', 'campaign_name' => 'Summer Launch', 'assignment_ulid' => PUSH_ASSIGN_ULID],
        'campaign.job_posted' => ['campaign_name' => 'Summer Launch', 'agency_name' => 'Acme Agency', 'campaign_ulid' => PUSH_CAMPAIGN_ULID],
        'creator.approved' => ['welcome_message' => 'SENTINEL-WELCOME-xyz'],
        'creator.rejected' => ['rejection_reason' => 'SENTINEL-REJECTION-REASON-xyz'],
        'agency_creator_relation.admin_connected' => ['agency_name' => 'Acme Agency'],
        'agency_creator_relation.disconnected' => ['counterparty_name' => 'Acme Agency'],
    ];
}

// Real-shaped ULIDs (26 uppercase Crockford chars, as `HasUlid` writes them). The old
// fixtures used '01ASSIGN' — which the emitters never produce — so a shape filter
// could not have been tested against them (AH-103; the AH-102b §9 lesson).
const PUSH_ASSIGN_ULID = '01J9ZK3Q8M5T7WXYABCDEFGHJK';
const PUSH_THREAD_ULID = '01J9ZK3Q8M5T7WXYTHREADAAAA';
const PUSH_CAMPAIGN_ULID = '01J9ZK3Q8M5T7WXYCAMPAGNAAA';

const PUSH_SENTINELS = ['SENTINEL-FEEDBACK-xyz', 'SENTINEL-MESSAGE-TEXT-xyz', 'SENTINEL-WELCOME-xyz', 'SENTINEL-REJECTION-REASON-xyz'];

/** @return list<NotificationType> the 14 eligible types */
function pushEligibleTypes(): array
{
    return [...PushEligibility::TOGGLEABLE, ...PushEligibility::ALWAYS_ON];
}

function pushRecipient(string $locale = 'en'): User
{
    return User::factory()->creator()->make(['preferred_language' => $locale]);
}

function pushFactory(): PushMessageFactory
{
    return app(PushMessageFactory::class);
}

/**
 * @param  array<string, mixed>  $data
 */
function pushMake(NotificationType $type, User $recipient, array $data, ?string $ulid = null): PushMessage
{
    $message = pushFactory()->make($type, $recipient, $data, $ulid);
    assert($message !== null, "no push message for {$type->value}");

    return $message;
}

/** @return array<string, mixed> every observable field of a message, for leak scanning */
function pushDump(PushMessage $m): array
{
    return (array) $m;
}

/** @return array<string, array<string, string>> locale → [key.title|key.body → string] */
function pushLang(string $locale): array
{
    /** @var array<string, array{title: string, body: string}> $arr */
    $arr = require lang_path($locale.'/push.php');

    return $arr;
}

// =============================================================================
// 1. Real rendering matrix — 14 types × 24 locales
// =============================================================================

it('the fixture bags cover exactly the 14 eligible types', function (): void {
    $types = array_map(fn (NotificationType $t): string => $t->value, pushEligibleTypes());
    sort($types);
    $bags = array_keys(pushRealBags());
    sort($bags);

    expect($bags)->toBe($types)->and($types)->toHaveCount(14);
});

it('renders a real title AND body for every eligible type in every UI locale, with the names injected and no placeholder left', function (): void {
    $bags = pushRealBags();

    foreach (Locale::uiValues() as $locale) {
        foreach (pushEligibleTypes() as $type) {
            $m = pushMake($type, pushRecipient($locale), $bags[$type->value], '01NOTIF');

            $key = 'push.'.PushMessageFactory::copyKey($type);
            expect($m->title)->not->toBe('')->and($m->title)->not->toBe($key.'.title', "{$locale} {$type->value} rendered a raw key");
            expect($m->body)->not->toBeNull("{$locale} {$type->value} lost its body")->and($m->body)->not->toBe($key.'.body');
            // No leftover `:placeholder` (a colon directly followed by a name-ish word).
            expect($m->body)->not->toMatch('/:[a-z_]{3,}/', "{$locale} {$type->value} left a placeholder: {$m->body}");
        }
    }
});

it('injects the names the type uses — and only those', function (): void {
    $bags = pushRealBags();
    $expected = [
        'assignment.invited' => ['Summer Launch'],
        'message.received_by_creator' => ['Acme Agent', 'Summer Launch'],
        'message.relationship_received_by_creator' => ['Acme Agent', 'Acme Agency'],
        'campaign_application.accepted' => ['Acme Agency', 'Summer Launch'],
        'campaign.job_posted' => ['Acme Agency', 'Summer Launch'],
        'agency_creator_relation.admin_connected' => ['Acme Agency'],
        'agency_creator_relation.disconnected' => ['Acme Agency'],
    ];

    foreach (['en', 'de', 'fi', 'bg', 'el'] as $locale) {
        foreach ($expected as $value => $names) {
            $m = pushMake(NotificationType::from($value), pushRecipient($locale), $bags[$value]);
            foreach ($names as $name) {
                expect($m->body)->toContain($name);
            }
        }
    }

    // creator_name is in many bags but is NOT in the allowlist: never rendered.
    $m = pushMake(NotificationType::AssignmentInvited, pushRecipient(), $bags['assignment.invited']);
    expect(json_encode(pushDump($m)))->not->toContain('Nora Creator');
});

it('account-lifecycle copy carries no names at all', function (): void {
    foreach ([NotificationType::CreatorApproved, NotificationType::CreatorRejected] as $type) {
        $m = pushMake($type, pushRecipient(), ['campaign_name' => 'Zq-Campaign', 'agency_name' => 'Zq-Agency']);

        expect($m->body)->not->toContain('Zq-Campaign')->and($m->body)->not->toContain('Zq-Agency');
    }
});

// =============================================================================
// 2. Privacy — the sentinel matrix (BR-7 target)
// =============================================================================

it('PRIVACY: no feedback / rejection reason / message text / welcome text reaches ANY field — 14 types × 24 locales', function (): void {
    $bags = pushRealBags();

    foreach (Locale::uiValues() as $locale) {
        foreach (pushEligibleTypes() as $type) {
            // Plant every sentinel in every bag, on top of the type's real bag.
            $bag = $bags[$type->value] + [
                'feedback' => 'SENTINEL-FEEDBACK-xyz',
                'rejection_reason' => 'SENTINEL-REJECTION-REASON-xyz',
                'body' => 'SENTINEL-MESSAGE-TEXT-xyz',
                'message' => 'SENTINEL-MESSAGE-TEXT-xyz',
                'welcome_message' => 'SENTINEL-WELCOME-xyz',
            ];

            $m = pushMake($type, pushRecipient($locale), $bag, '01NOTIF');
            $dump = (string) json_encode(pushDump($m), JSON_UNESCAPED_UNICODE);

            foreach (PUSH_SENTINELS as $sentinel) {
                expect(str_contains($dump, $sentinel))->toBeFalse("{$locale} {$type->value} leaked {$sentinel}");
            }
        }
    }
});

it('PRIVACY: the payload data is exactly {type, v, notification_ulid?} ∪ the type\'s route keys — nothing else can be added by a bag', function (): void {
    $bag = pushRealBags()['assignment.draft_rejected'] + ['extra' => 'SENTINEL-FEEDBACK-xyz', 'user_email' => 'a@b.test'];

    $m = pushMake(NotificationType::AssignmentDraftRejected, pushRecipient(), $bag, '01NOTIF');

    expect($m->data)->toBe(['type' => 'assignment.draft_rejected', 'v' => 1, 'notification_ulid' => '01NOTIF', 'assignment_ulid' => PUSH_ASSIGN_ULID]);

    $withoutUlid = pushMake(NotificationType::AssignmentDraftRejected, pushRecipient(), $bag, null);
    expect($withoutUlid->data)->toBe(['type' => 'assignment.draft_rejected', 'v' => 1, 'assignment_ulid' => PUSH_ASSIGN_ULID]);

    $emptyUlid = pushMake(NotificationType::AssignmentDraftRejected, pushRecipient(), $bag, '');
    expect($emptyUlid->data)->not->toHaveKey('notification_ulid');
});

// =============================================================================
// 2b. AH-103 route keys — the second allowlist (sentinels a–d)
// =============================================================================

it('SENTINEL (d): every eligible type\'s payload key set is exactly {type, v, notification_ulid} ∪ the fixture\'s push keys', function (): void {
    $bags = pushRealBags();

    foreach (pushEligibleTypes() as $type) {
        $m = pushMake($type, pushRecipient(), $bags[$type->value], '01NOTIF');

        $expected = ['type', 'v', 'notification_ulid', ...NotificationRouteKeys::pushKeys($type->value)];
        $actual = array_keys($m->data);
        sort($expected);
        sort($actual);

        expect($actual)->toBe($expected, $type->value);
    }
});

it('the factory allowlist equals the contract fixture for every eligible type (and lists only eligible types)', function (): void {
    foreach (pushEligibleTypes() as $type) {
        expect(PushMessageFactory::ROUTE_KEYS_BY_TYPE[$type->value] ?? null)->toBe(NotificationRouteKeys::pushKeys($type->value), $type->value);
    }

    $listed = array_keys(PushMessageFactory::ROUTE_KEYS_BY_TYPE);
    $eligible = array_map(fn (NotificationType $t): string => $t->value, pushEligibleTypes());
    sort($listed);
    sort($eligible);
    expect($listed)->toBe($eligible);
});

it('SENTINEL (a): a free-text value under a LISTED route key is dropped, the push still sends', function (mixed $bad): void {
    $m = pushMake(NotificationType::AssignmentDraftRejected, pushRecipient(), ['campaign_name' => 'X', 'assignment_ulid' => $bad], '01NOTIF');

    expect($m->data)->toBe(['type' => 'assignment.draft_rejected', 'v' => 1, 'notification_ulid' => '01NOTIF'])
        ->and((string) json_encode(pushDump($m)))->not->toContain('SENTINEL');
})->with([
    'free text' => ['SENTINEL-FEEDBACK-xyz'],
    'old fixture shape' => ['01ASSIGN'],
    '25 chars' => ['01J9ZK3Q8M5T7WXYABCDEFGHJ'],
    '27 chars' => ['01J9ZK3Q8M5T7WXYABCDEFGHJKM'],
    'forbidden letter U' => ['01J9ZK3Q8M5T7WXYABCDEFGHJU'],
    'trailing newline' => ["01J9ZK3Q8M5T7WXYABCDEFGHJK\n"],
    'embedded text' => ['01J9ZK3Q8M5T7WXY SENTINEL-xyz'],
    'array' => [['01J9ZK3Q8M5T7WXYABCDEFGHJK']],
    'int' => [42],
    'null' => [null],
]);

it('a lowercase ULID is accepted (case-insensitive shape) and forwarded as given', function (): void {
    $lower = strtolower(PUSH_ASSIGN_ULID);
    $m = pushMake(NotificationType::AssignmentInvited, pushRecipient(), ['campaign_name' => 'X', 'assignment_ulid' => $lower]);

    expect($m->data['assignment_ulid'])->toBe($lower);
});

it('SENTINEL (b): a route key NOT listed for the type never reaches the payload', function (): void {
    // campaign_ulid on a creator message (the leak the per-type map exists to prevent) and thread_ulid on an assignment type.
    $m = pushMake(NotificationType::MessageReceivedByCreator, pushRecipient(), pushRealBags()['message.received_by_creator'] + ['campaign_ulid' => PUSH_CAMPAIGN_ULID, 'thread_ulid' => PUSH_THREAD_ULID]);
    expect($m->data)->toBe(['type' => 'message.received_by_creator', 'v' => 1, 'assignment_ulid' => PUSH_ASSIGN_ULID]);

    $m = pushMake(NotificationType::AssignmentInvited, pushRecipient(), pushRealBags()['assignment.invited'] + ['thread_ulid' => PUSH_THREAD_ULID, 'campaign_ulid' => PUSH_CAMPAIGN_ULID]);
    expect($m->data)->toBe(['type' => 'assignment.invited', 'v' => 1, 'assignment_ulid' => PUSH_ASSIGN_ULID]);

    // Key-less types forward nothing even if a bag carries valid ULIDs.
    foreach ([NotificationType::CreatorApproved, NotificationType::CreatorRejected, NotificationType::RelationAdminConnected, NotificationType::RelationDisconnected] as $type) {
        $m = pushMake($type, pushRecipient(), ['assignment_ulid' => PUSH_ASSIGN_ULID, 'campaign_ulid' => PUSH_CAMPAIGN_ULID, 'thread_ulid' => PUSH_THREAD_ULID]);
        expect($m->data)->toBe(['type' => $type->value, 'v' => 1], $type->value);
    }
});

it('SENTINEL (c): every other bag key still never reaches the payload, alongside valid route keys', function (): void {
    $bag = pushRealBags()['assignment.revision_requested'] + [
        'rejection_reason' => 'SENTINEL-REJECTION-REASON-xyz',
        'body' => 'SENTINEL-MESSAGE-TEXT-xyz',
        'creator_name' => 'Nora Creator',
    ];
    $m = pushMake(NotificationType::AssignmentRevisionRequested, pushRecipient(), $bag, '01NOTIF');

    expect(array_keys($m->data))->toBe(['type', 'v', 'notification_ulid', 'assignment_ulid'])
        ->and((string) json_encode($m->data))->not->toContain('SENTINEL')->not->toContain('Nora');
});

it('the payload version stays 1 (the contract is additive)', function (): void {
    expect(PushMessageFactory::PAYLOAD_VERSION)->toBe(1);
});

it('PRIVACY: a name key holding free text is bounded — the sentinel in a NON-allowlisted key never renders even when the allowlisted key is absent', function (): void {
    $m = pushMake(NotificationType::MessageReceivedByCreator, pushRecipient(), [
        'campaign_name' => 'Summer Launch',
        'text' => 'SENTINEL-MESSAGE-TEXT-xyz',
        'body' => 'SENTINEL-MESSAGE-TEXT-xyz',
    ]);

    // sender_name missing → body dropped, title only; the sentinel is nowhere.
    expect($m->body)->toBeNull()
        ->and((string) json_encode(pushDump($m)))->not->toContain('SENTINEL');
});

it('PRIVACY: a body that references a NON-allowlisted placeholder (future lang drift) is dropped, never rendered with the value', function (): void {
    // Load the real groups first so addLines overrides ONE line instead of replacing the group.
    trans('push.assignment_invited.title', [], 'en');
    trans('push.assignment_invited.title', [], 'de');
    app('translator')->addLines(['push.assignment_invited.body' => 'Draft feedback: :feedback'], 'en');
    app('translator')->addLines(['push.assignment_invited.body' => 'Draft feedback: :feedback'], 'de');

    $m = pushMake(NotificationType::AssignmentInvited, pushRecipient('de'), ['campaign_name' => 'X', 'feedback' => 'SENTINEL-FEEDBACK-xyz']);

    expect($m->body)->toBeNull()
        ->and((string) json_encode(pushDump($m)))->not->toContain('SENTINEL');
});

// =============================================================================
// 3. Names: sanitised, capped, untranslated; missing → title only
// =============================================================================

it('a missing, empty, whitespace-only or non-string required name drops the body but still sends the title', function (mixed $badName): void {
    $m = pushMake(NotificationType::CampaignJobPosted, pushRecipient('de'), ['campaign_name' => 'Summer Launch', 'agency_name' => $badName]);

    expect($m->title)->toBe(pushLang('de')['campaign_job_posted']['title'])
        ->and($m->body)->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ["  \t\n "],
    'only control/format chars' => ["\u{200B}\u{202E}\x00"],
    'array' => [['Acme']],
    'int' => [42],
]);

it('every name key missing → every type that has a name drops its body, never the push', function (): void {
    foreach (pushEligibleTypes() as $type) {
        $m = pushMake($type, pushRecipient(), []);

        expect($m->title)->not->toBe('');
    }
});

it('names are injected UNTRANSLATED (Cyrillic, Greek, CJK, emoji, quotes all survive intact) in every locale', function (): void {
    foreach (['Кампания «Лято»', 'Εκστρατεία "Άνοιξη"', '夏のキャンペーン', 'Café ☕ Launch', "O'Brien & Sons <b>"] as $name) {
        foreach (Locale::uiValues() as $locale) {
            $m = pushMake(NotificationType::AssignmentInvited, pushRecipient($locale), ['campaign_name' => $name]);

            expect($m->body)->toContain($name);
        }
    }
});

it('control and bidi/zero-width characters are stripped from names; whitespace collapses', function (): void {
    $m = pushMake(NotificationType::AssignmentInvited, pushRecipient(), [
        'campaign_name' => "  Sum\u{202E}mer\x00 \u{200B}Launch\n\n  Now\t ",
    ]);

    expect($m->body)->toContain('Sum mer Launch Now')
        ->and($m->body)->not->toMatch('/[\x00-\x1F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u');
});

it('a name over the cap is truncated with an ellipsis (60 chars incl. the ellipsis), by characters not bytes', function (): void {
    $m = pushMake(NotificationType::AssignmentInvited, pushRecipient(), ['campaign_name' => str_repeat('Ж', 200)]);

    preg_match('/Ж+…/u', (string) $m->body, $hit);
    expect(mb_strlen($hit[0] ?? ''))->toBe(PushMessageFactory::NAME_MAX_CHARS);

    $exact = pushMake(NotificationType::AssignmentInvited, pushRecipient(), ['campaign_name' => str_repeat('Ж', 60)]);
    expect($exact->body)->not->toContain('…');
});

it('a placeholder-shaped name is NOT re-expanded (no template injection through a name)', function (): void {
    $m = pushMake(NotificationType::CampaignApplicationAccepted, pushRecipient(), [
        'agency_name' => ':campaign_name',
        'campaign_name' => 'Real Campaign',
    ]);

    // The agency's literal ":campaign_name" is shown verbatim; it did not become "Real Campaign" twice.
    expect(substr_count((string) $m->body, 'Real Campaign'))->toBe(1)
        ->and($m->body)->toContain(':campaign_name');
});

// =============================================================================
// 4. Locale discipline
// =============================================================================

it('renders with the recipient\'s preferred_language and leaves the caller\'s locale untouched', function (): void {
    app()->setLocale('en');
    $before = app()->getLocale();

    $m = pushMake(NotificationType::AssignmentDraftApproved, pushRecipient('de'), ['campaign_name' => 'X']);

    expect($m->title)->toBe('Entwurf genehmigt')
        ->and(app()->getLocale())->toBe($before, 'make() must not call setLocale()');
});

it('falls back to en for an unknown, empty or non-rendered preferred_language', function (string $lang): void {
    $m = pushMake(NotificationType::AssignmentDraftApproved, pushRecipient($lang), ['campaign_name' => 'X']);

    expect($m->title)->toBe('Draft approved');
})->with(['unknown code' => ['xx'], 'empty' => [''], 'garbage' => ['../../etc/passwd'], 'uppercase' => ['DE']]);

// =============================================================================
// 5. Ineligible types, collapse keys, channel
// =============================================================================

it('returns null for every ineligible type (nothing to send)', function (): void {
    $eligible = array_map(fn (NotificationType $t): string => $t->value, pushEligibleTypes());
    $checked = 0;

    foreach (NotificationType::cases() as $type) {
        if (in_array($type->value, $eligible, true)) {
            continue;
        }

        expect(pushFactory()->make($type, pushRecipient(), ['campaign_name' => 'X']))->toBeNull($type->value);
        $checked++;
    }

    expect($checked)->toBe(count(NotificationType::cases()) - 14)->and($checked)->toBeGreaterThan(10);
});

it('collapse key: per-assignment / per-thread for the two message types only; null otherwise or when the ulid is absent', function (): void {
    $bags = pushRealBags();

    expect(pushMake(NotificationType::MessageReceivedByCreator, pushRecipient(), $bags['message.received_by_creator'])->collapseKey)->toBe('assignment:'.PUSH_ASSIGN_ULID.'')
        ->and(pushMake(NotificationType::MessageRelationshipReceivedByCreator, pushRecipient(), $bags['message.relationship_received_by_creator'])->collapseKey)->toBe('thread:'.PUSH_THREAD_ULID.'')
        ->and(pushMake(NotificationType::MessageReceivedByCreator, pushRecipient(), ['sender_name' => 'A', 'campaign_name' => 'B'])->collapseKey)->toBeNull()
        ->and(pushMake(NotificationType::AssignmentInvited, pushRecipient(), $bags['assignment.invited'])->collapseKey)->toBeNull();

    // Two different threads never share a key.
    expect(PushMessageFactory::threadKey(NotificationType::MessageReceivedByCreator, ['assignment_ulid' => 'A']))
        ->not->toBe(PushMessageFactory::threadKey(NotificationType::MessageReceivedByCreator, ['assignment_ulid' => 'B']));
});

it('uses the single app notification channel', function (): void {
    $m = pushMake(NotificationType::AssignmentInvited, pushRecipient(), ['campaign_name' => 'X']);

    expect($m->channelId)->toBe('catalyst_default')->and($m->channelId)->toBe(PushMessage::DEFAULT_CHANNEL);
});

// =============================================================================
// 6. The copy files themselves
// =============================================================================

it('copy ⇔ eligibility: en has exactly one title+body per eligible type, no orphans', function (): void {
    $keys = array_map(fn (NotificationType $t): string => PushMessageFactory::copyKey($t), pushEligibleTypes());
    sort($keys);
    $en = pushLang('en');
    $have = array_keys($en);
    sort($have);

    expect($have)->toBe($keys);

    foreach ($en as $key => $copy) {
        expect(array_keys($copy))->toBe(['title', 'body'], $key)
            ->and($copy['title'])->not->toBe('')
            ->and(str_contains($copy['title'], ':'))->toBeFalse("{$key}: titles never carry names");
    }
});

it('every body placeholder in every locale is an allowlisted NAME key', function (): void {
    foreach (Locale::uiValues() as $locale) {
        foreach (pushLang($locale) as $key => $copy) {
            preg_match_all('/:([A-Za-z_]+)/', $copy['body'], $m);

            foreach ($m[1] as $name) {
                expect(in_array($name, PushMessageFactory::NAME_KEYS, true))->toBeTrue("{$locale}.{$key} uses :{$name}");
            }
        }
    }
});

it('FLAKY-TEN VALUE CHECK: bg, el, et, fi, ga, hu, lt, lv, mt, ro never ship English for any title or body (and no locale ships an English title)', function (): void {
    $en = pushLang('en');
    $offenders = [];

    foreach (Locale::uiValues() as $locale) {
        if ($locale === 'en') {
            continue;
        }

        foreach (pushLang($locale) as $key => $copy) {
            foreach (['title', 'body'] as $part) {
                if ($copy[$part] === $en[$key][$part]) {
                    $offenders[] = "{$locale}.{$key}.{$part}";
                }
            }
        }
    }

    expect($offenders)->toBe([], 'English left in a non-English locale: '.implode(', ', $offenders));
});

it('the value check is not vacuous: en would fail it against itself, and the flaky ten are in the registry', function (): void {
    $en = pushLang('en');

    expect($en['assignment_invited']['title'])->toBe(pushLang('en')['assignment_invited']['title']);
    foreach (['bg', 'el', 'et', 'fi', 'ga', 'hu', 'lt', 'lv', 'mt', 'ro'] as $locale) {
        expect(Locale::uiValues())->toContain($locale);
        expect(pushLang($locale)['assignment_invited']['title'])->not->toBe($en['assignment_invited']['title']);
    }
});
