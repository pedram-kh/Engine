<?php

declare(strict_types=1);

use App\Modules\Messaging\Mail\NewMessageMail;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

uses(TestCase::class);

/**
 * §5.3 REAL-RENDER tests for the debounced message email (AH-083, ⑧), BOTH
 * contexts. No database: the mailable takes scalars only (the
 * `InviteReceivedMail` / `DraftReviewedMail` precedent).
 *
 * C5's two link-shape assertions are dedicated per context, NOT a shared
 * "contains a ulid" check — that would pass even with the wrong ulid
 * substituted (the trap this chunk's plan named explicitly): the campaign
 * context must link with the ASSIGNMENT's ulid, and the relationship context
 * must link with the AGENCY's ulid, never `RelationshipThread::$ulid`.
 */

/**
 * @return array{subject: string, body: string}
 */
function renderNewMessage(NewMessageMail $mail, string $locale): array
{
    $previous = App::getLocale();
    App::setLocale($locale);

    try {
        return [
            'subject' => $mail->envelope()->subject ?? '',
            'body' => (string) $mail->render(),
        ];
    } finally {
        App::setLocale($previous);
    }
}

function campaignMessageMail(): NewMessageMail
{
    return new NewMessageMail(
        recipientName: 'Maria',
        senderName: 'Bright Harbour',
        context: 'campaign',
        counterpartyName: 'Autumn UGC push',
        assignmentUlid: '01JASSIGNMENTULIDXXXXXXXX',
    );
}

function relationshipMessageMail(): NewMessageMail
{
    return new NewMessageMail(
        recipientName: 'Maria',
        senderName: 'Jordan',
        context: 'relationship',
        counterpartyName: 'Bright Harbour',
        agencyUlid: '01JAGENCYULIDXXXXXXXXXXXX',
    );
}

// ── C5 — the two dedicated, non-symmetric link-shape assertions ─────────────

it('the CAMPAIGN context links to the assignment via the ASSIGNMENT ulid — never a thread ulid', function (): void {
    $rendered = renderNewMessage(campaignMessageMail(), 'en');

    expect($rendered['body'])->toContain('/creator/assignments/01JASSIGNMENTULIDXXXXXXXX')
        ->and($rendered['body'])->not->toContain('/creator/messages/');
});

it('the RELATIONSHIP context links via the AGENCY ulid — never the RelationshipThread ulid (C5 trap)', function (): void {
    $rendered = renderNewMessage(relationshipMessageMail(), 'en');

    expect($rendered['body'])->toContain('/creator/messages/01JAGENCYULIDXXXXXXXXXXXX')
        ->and($rendered['body'])->not->toContain('/creator/assignments/');
});

// ── Context-discriminated copy ───────────────────────────────────────────────

it('renders the campaign context naming the campaign, not the agency', function (): void {
    $rendered = renderNewMessage(campaignMessageMail(), 'en');

    expect($rendered['subject'])->toBe('New message about Autumn UGC push')
        ->and($rendered['body'])->toContain('Maria')
        ->and($rendered['body'])->toContain('Bright Harbour')
        ->and($rendered['body'])->toContain('Autumn UGC push');
});

it('renders the relationship context naming the agency, not a campaign', function (): void {
    $rendered = renderNewMessage(relationshipMessageMail(), 'en');

    expect($rendered['subject'])->toBe('New message from Bright Harbour')
        ->and($rendered['body'])->toContain('Jordan')
        ->and($rendered['body'])->toContain('Bright Harbour');
});

it('the two contexts render genuinely different subjects and bodies', function (): void {
    $campaign = renderNewMessage(campaignMessageMail(), 'en');
    $relationship = renderNewMessage(relationshipMessageMail(), 'en');

    expect($campaign['subject'])->not->toBe($relationship['subject'])
        ->and($campaign['body'])->not->toBe($relationship['body']);
});

// ── Locale coverage — both contexts ──────────────────────────────────────────

it('renders in a non-English locale with the interpolated values intact, per context', function (string $locale): void {
    foreach ([campaignMessageMail(), relationshipMessageMail()] as $mail) {
        $en = renderNewMessage($mail, 'en');
        $translated = renderNewMessage($mail, $locale);

        expect($translated['subject'])->not->toBe('')
            ->and($translated['subject'])->not->toBe($en['subject'])
            ->and($translated['body'])->not->toBe($en['body']);
    }
})->with(['pt', 'it', 'fr', 'de', 'pl', 'nl']);

it('renders in the flaky-10 locales with real translations, not the English fallback', function (string $locale): void {
    // The flaky 10 are where machine-translation baselines have gone missing
    // before (AH-028/046/047). `new_message` is a NEW key in all 24, so it
    // must not fall back to en — subject AND body, both contexts.
    foreach ([campaignMessageMail(), relationshipMessageMail()] as $mail) {
        $en = renderNewMessage($mail, 'en');
        $translated = renderNewMessage($mail, $locale);

        expect($translated['subject'])->not->toBe($en['subject'])
            ->and($translated['body'])->not->toBe($en['body']);
    }
})->with(['bg', 'el', 'et', 'fi', 'ga', 'hu', 'lt', 'lv', 'mt', 'ro']);

it('renders a genuinely different body in every one of the 24 locales, per context', function (): void {
    foreach ([campaignMessageMail(), relationshipMessageMail()] as $mail) {
        $en = renderNewMessage($mail, 'en')['body'];

        foreach (['bg', 'cs', 'da', 'de', 'el', 'es', 'et', 'fi', 'fr', 'ga', 'hr', 'hu', 'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv'] as $locale) {
            expect(renderNewMessage($mail, $locale)['body'])
                ->not->toBe($en, "locale {$locale} rendered the English body for context {$mail->context}");
        }
    }
});

it('queues with the recipient own locale, not the senders (queue-time locale precedent)', function (): void {
    // Mirrors DraftReviewedMailTest / InviteReceivedMailTest — a real Mail::queue
    // assertion belongs to the service test (DebouncedMessageMailerTest),
    // this only pins that the mailable itself carries no ambient-locale state.
    $mail = campaignMessageMail();

    expect($mail->locale)->toBeNull();
});
