<?php

declare(strict_types=1);

use App\Modules\Campaigns\Mail\InviteReceivedMail;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

uses(TestCase::class);

/**
 * §5.3 REAL-RENDER tests for the creator-facing invite mail (AH-083, ①).
 *
 * No database: the mailable takes scalars only, which is what makes it
 * renderable in isolation — the `DraftReviewedMail` precedent.
 */

/**
 * @return array{subject: string, body: string}
 */
function renderInviteReceived(InviteReceivedMail $mail, string $locale): array
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

/**
 * @param  'fresh'|'re_offer'  $outcome
 */
function inviteMail(string $outcome): InviteReceivedMail
{
    return new InviteReceivedMail(
        creatorName: 'Maria',
        campaignName: 'Autumn UGC push',
        outcome: $outcome,
        assignmentUlid: '01JASSIGNMENTULID',
    );
}

it('renders the fresh-invite outcome with its own subject and body', function (): void {
    $rendered = renderInviteReceived(inviteMail('fresh'), 'en');

    expect($rendered['subject'])->toBe('You have a new offer for Autumn UGC push')
        ->and($rendered['body'])->toContain('Maria')
        ->and($rendered['body'])->toContain('Autumn UGC push')
        ->and($rendered['body'])->toContain("You've been invited")
        ->and($rendered['body'])->toContain('/creator/assignments/01JASSIGNMENTULID');
});

it('renders the re_offer outcome with different copy than fresh (both re-invite paths share it)', function (): void {
    $fresh = renderInviteReceived(inviteMail('fresh'), 'en');
    $reOffer = renderInviteReceived(inviteMail('re_offer'), 'en');

    expect($reOffer['subject'])->toBe('An updated offer for Autumn UGC push')
        ->and($reOffer['subject'])->not->toBe($fresh['subject'])
        ->and($reOffer['body'])->not->toBe($fresh['body'])
        ->and($reOffer['body'])->toContain('updated offer')
        ->and($reOffer['body'])->toContain('/creator/assignments/01JASSIGNMENTULID');
});

// ── Locale coverage ─────────────────────────────────────────────────────────

it('renders in a non-English locale with the interpolated values intact', function (string $locale): void {
    $mail = inviteMail('re_offer');
    $en = renderInviteReceived($mail, 'en');
    $translated = renderInviteReceived($mail, $locale);

    expect($translated['subject'])->not->toBe('')
        ->and($translated['subject'])->not->toBe($en['subject'])
        ->and($translated['body'])->not->toBe($en['body'])
        ->and($translated['body'])->toContain('Autumn UGC push')
        ->and($translated['body'])->toContain('Maria');
})->with(['pt', 'it', 'fr', 'de', 'pl', 'nl']);

it('renders in the flaky-10 locales with real translations, not the English fallback', function (string $locale): void {
    // The flaky 10 are where machine-translation baselines have gone missing
    // before (AH-028/046/047). `invite_received` is a NEW key in all 24, so it
    // must not fall back to en — subject AND body, both outcomes.
    foreach (['fresh', 're_offer'] as $outcome) {
        $mail = inviteMail($outcome);
        $en = renderInviteReceived($mail, 'en');
        $translated = renderInviteReceived($mail, $locale);

        expect($translated['subject'])->not->toBe($en['subject'])
            ->and($translated['body'])->not->toBe($en['body']);
    }
})->with(['bg', 'el', 'et', 'fi', 'ga', 'hu', 'lt', 'lv', 'mt', 'ro']);

it('renders a genuinely different body in every one of the 24 locales', function (): void {
    $mail = inviteMail('fresh');
    $en = renderInviteReceived($mail, 'en')['body'];

    foreach (['bg', 'cs', 'da', 'de', 'el', 'es', 'et', 'fi', 'fr', 'ga', 'hr', 'hu', 'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv'] as $locale) {
        expect(renderInviteReceived($mail, $locale)['body'])
            ->not->toBe($en, "locale {$locale} rendered the English body");
    }
});
