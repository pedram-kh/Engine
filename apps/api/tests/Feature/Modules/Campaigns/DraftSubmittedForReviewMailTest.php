<?php

declare(strict_types=1);

use App\Modules\Campaigns\Mail\DraftSubmittedForReviewMail;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

uses(TestCase::class);

/**
 * §5.3 REAL-RENDER tests for the agency-facing submission mail (AH-068, D4).
 *
 * Like its review-mail sibling, this shipped with `Mail::assertQueued` coverage
 * only. It is also the mail where the round matters most: resubmission re-fires
 * the SAME `assignment.draft_submitted` verb as a first submission, so before
 * AH-068 nothing in the message distinguished "they sent their first draft" from
 * "they sent their third".
 *
 * No database: the mailable takes scalars only.
 */

/**
 * @return array{subject: string, body: string}
 */
function renderDraftSubmitted(DraftSubmittedForReviewMail $mail, string $locale): array
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

function submittedForReviewMail(?int $round = null): DraftSubmittedForReviewMail
{
    return new DraftSubmittedForReviewMail(
        recipientName: 'Dana',
        creatorName: 'Maria',
        campaignName: 'Autumn UGC push',
        campaignUlid: '01JCAMPAIGNULID',
        round: $round,
    );
}

it('renders the recipient, the creator, the campaign and the campaign deep link', function (): void {
    $rendered = renderDraftSubmitted(submittedForReviewMail(3), 'en');

    expect($rendered['subject'])->toBe('Maria submitted a draft for review (Draft 3)')
        ->and($rendered['body'])->toContain('Dana')
        ->and($rendered['body'])->toContain('Maria')
        ->and($rendered['body'])->toContain('Autumn UGC push')
        ->and($rendered['body'])->toContain('Draft 3')
        ->and($rendered['body'])->toContain('/campaigns/01JCAMPAIGNULID');
});

it('distinguishes a resubmission from a first submission — the whole point of the round here', function (): void {
    $first = renderDraftSubmitted(submittedForReviewMail(1), 'en');
    $third = renderDraftSubmitted(submittedForReviewMail(3), 'en');

    // Same verb, same mailable, same recipient — different message.
    expect($first['subject'])->not->toBe($third['subject'])
        ->and($first['subject'])->toContain('Draft 1')
        ->and($third['subject'])->toContain('Draft 3')
        ->and($first['body'])->not->toBe($third['body']);
});

// ── The omit-when-absent invariant (D4 / Q2) ─────────────────────────────────

it('renders with NO round exactly as it did before the round existed', function (): void {
    $rendered = renderDraftSubmitted(submittedForReviewMail(), 'en');

    expect($rendered['subject'])->toBe('Maria submitted a draft for review')
        ->and($rendered['subject'])->not->toContain('(')
        ->and($rendered['body'])->not->toContain('Draft ')
        ->and($rendered['body'])->toContain('Dana')
        ->and($rendered['body'])->toContain('/campaigns/01JCAMPAIGNULID');
});

// ── Locale coverage ─────────────────────────────────────────────────────────

it('renders in a non-English locale with the interpolated values intact', function (string $locale): void {
    $mail = submittedForReviewMail(2);
    $en = renderDraftSubmitted($mail, 'en');
    $translated = renderDraftSubmitted($mail, $locale);

    expect($translated['subject'])->not->toBe('')
        ->and($translated['subject'])->not->toBe($en['subject'])
        ->and($translated['body'])->not->toBe($en['body'])
        ->and($translated['body'])->toContain('Autumn UGC push')
        ->and($translated['body'])->toContain('Maria')
        ->and($translated['body'])->toContain('2');
})->with(['pt', 'it', 'fr', 'de', 'pl', 'nl']);

it('renders in the flaky-10 locales with real translations, not the English fallback', function (string $locale): void {
    $mail = submittedForReviewMail(2);
    $en = renderDraftSubmitted($mail, 'en');
    $translated = renderDraftSubmitted($mail, $locale);

    expect($translated['subject'])->not->toBe($en['subject'])
        ->and($translated['body'])->not->toBe($en['body'])
        ->and($translated['body'])->not->toContain('Draft 2');
})->with(['bg', 'el', 'et', 'fi', 'ga', 'hu', 'lt', 'lv', 'mt', 'ro']);
