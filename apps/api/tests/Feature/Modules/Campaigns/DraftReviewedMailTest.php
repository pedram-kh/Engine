<?php

declare(strict_types=1);

use App\Modules\Campaigns\Mail\DraftReviewedMail;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

uses(TestCase::class);

/**
 * §5.3 REAL-RENDER tests for the creator-facing review mail (AH-068, D4).
 *
 * This mailable shipped in Sprint 9 Chunk 2 with queue assertions only —
 * `Mail::assertQueued(DraftReviewedMail::class, …)` in three specs, which renders
 * nothing. A broken Blade conditional or a missing locale value would have sailed
 * past all of them and reached a creator as a stack trace. AH-068 changes this
 * mail's copy, so the gap closes here rather than being widened.
 *
 * No database: the mailable takes scalars only, which is what makes it renderable
 * in isolation.
 */

/**
 * Render subject + HTML body inside a locale. `Mailable::locale()` defers the real
 * switch to send time, so a direct render needs an explicit App locale (the
 * job-posted / application-mail precedent).
 *
 * @return array{subject: string, body: string}
 */
function renderDraftReviewed(DraftReviewedMail $mail, string $locale): array
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
 * @param  'approved'|'revision_requested'|'rejected'  $outcome
 */
function reviewedMail(string $outcome, ?string $feedback = null, ?int $round = null): DraftReviewedMail
{
    return new DraftReviewedMail(
        creatorName: 'Maria',
        campaignName: 'Autumn UGC push',
        outcome: $outcome,
        feedback: $feedback,
        assignmentUlid: '01JASSIGNMENTULID',
        round: $round,
    );
}

it('carries the round in the subject and names it in the body', function (): void {
    $rendered = renderDraftReviewed(reviewedMail('revision_requested', 'Brighten the lighting.', 2), 'en');

    expect($rendered['subject'])->toBe('Changes requested on your Autumn UGC push draft (Draft 2)')
        ->and($rendered['body'])->toContain('Draft 2')
        ->and($rendered['body'])->toContain('Maria')
        ->and($rendered['body'])->toContain('Autumn UGC push')
        // The feedback rides verbatim, and the deep link points at the assignment.
        ->and($rendered['body'])->toContain('Brighten the lighting.')
        ->and($rendered['body'])->toContain('/creator/assignments/01JASSIGNMENTULID');
});

it('renders each of the three outcomes with its own subject and body', function (): void {
    $approved = renderDraftReviewed(reviewedMail('approved', null, 3), 'en');
    $revision = renderDraftReviewed(reviewedMail('revision_requested', 'Fix the audio.', 3), 'en');
    $rejected = renderDraftReviewed(reviewedMail('rejected', 'Off brief.', 3), 'en');

    expect($approved['subject'])->toBe('Your draft for Autumn UGC push was approved (Draft 3)')
        ->and($rejected['subject'])->toBe('An update on your Autumn UGC push draft (Draft 3)')
        // Three genuinely different bodies — an outcome that rendered the same
        // sentence would make the parameter decorative.
        ->and($approved['body'])->not->toBe($revision['body'])
        ->and($revision['body'])->not->toBe($rejected['body'])
        ->and($approved['body'])->toContain('was approved')
        ->and($rejected['body'])->toContain('not accepted');
});

// ── The omit-when-absent invariant (D4 / Q2) ─────────────────────────────────

it('renders with NO round exactly as it did before the round existed', function (): void {
    // A direct state-machine call carries no context, so the listener passes no
    // round. The mail must then read as it always did — no empty parentheses in
    // the subject, no dangling label in the body.
    $rendered = renderDraftReviewed(reviewedMail('approved'), 'en');

    expect($rendered['subject'])->toBe('Your draft for Autumn UGC push was approved')
        ->and($rendered['subject'])->not->toContain('(')
        ->and($rendered['body'])->not->toContain('Draft ')
        // …and the mail is still a complete, sendable message.
        ->and($rendered['body'])->toContain('Maria')
        ->and($rendered['body'])->toContain('/creator/assignments/01JASSIGNMENTULID');
});

it('omits the feedback block when a round closed without a note', function (): void {
    $withFeedback = renderDraftReviewed(reviewedMail('revision_requested', 'Fix the audio.', 2), 'en');
    $without = renderDraftReviewed(reviewedMail('approved', null, 2), 'en');

    expect($withFeedback['body'])->toContain('Feedback')
        ->and($without['body'])->not->toContain('Feedback');
});

// ── Locale coverage ─────────────────────────────────────────────────────────

it('renders in a non-English locale with the interpolated values intact', function (string $locale): void {
    $mail = reviewedMail('revision_requested', 'Brighten the lighting.', 2);
    $en = renderDraftReviewed($mail, 'en');
    $translated = renderDraftReviewed($mail, $locale);

    expect($translated['subject'])->not->toBe('')
        ->and($translated['subject'])->not->toBe($en['subject'])
        ->and($translated['body'])->not->toBe($en['body'])
        // Values must survive translation — including the round number itself.
        ->and($translated['body'])->toContain('Autumn UGC push')
        ->and($translated['body'])->toContain('Maria')
        ->and($translated['body'])->toContain('2')
        ->and($translated['body'])->toContain('Brighten the lighting.');
})->with(['pt', 'it', 'fr', 'de', 'pl', 'nl']);

it('renders in the flaky-10 locales with real translations, not the English fallback', function (string $locale): void {
    // The flaky 10 are where machine-translation baselines have gone missing
    // before (AH-028/046/047). The round clause is a NEW key in all 24, so it
    // must not fall back to en — subject AND body.
    foreach (['approved', 'revision_requested', 'rejected'] as $outcome) {
        $mail = reviewedMail($outcome, 'Fix the audio.', 2);
        $en = renderDraftReviewed($mail, 'en');
        $translated = renderDraftReviewed($mail, $locale);

        expect($translated['subject'])->not->toBe($en['subject'])
            ->and($translated['body'])->not->toBe($en['body'])
            // The round label specifically: an en fallback would read "Draft 2".
            ->and($translated['body'])->not->toContain('Draft 2');
    }
})->with(['bg', 'el', 'et', 'fi', 'ga', 'hu', 'lt', 'lv', 'mt', 'ro']);

it('translates the round label itself in every rendered locale', function (): void {
    // One assertion over all 24: the label must differ from en everywhere it can.
    // `hr`/`sk`/`sl` are included deliberately — Czech text bled into their SPA
    // draft keys once (AH-046's class), so their round label is worth pinning.
    $mail = reviewedMail('approved', null, 2);
    $en = renderDraftReviewed($mail, 'en')['body'];

    foreach (['bg', 'cs', 'da', 'de', 'el', 'es', 'et', 'fi', 'fr', 'ga', 'hr', 'hu', 'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv'] as $locale) {
        expect(renderDraftReviewed($mail, $locale)['body'])
            ->not->toBe($en, "locale {$locale} rendered the English body");
    }
});
