<?php

declare(strict_types=1);

use App\Modules\Campaigns\Mail\AssignmentCompletedOnApprovalMail;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

uses(TestCase::class);

/**
 * §5.3 REAL-RENDER tests for the AH-069 completion mail (D5).
 *
 * AH-068 made the render test the house standard for a creator-facing mailable,
 * on the argument that a queue assertion renders nothing — a broken Blade
 * conditional or a missing locale value reaches the creator as a stack trace.
 * A NEW mailable therefore arrives with this file rather than earning it later.
 *
 * The load-bearing assertion here is not "it renders". It is that the copy never
 * claims a post or a verification, in any of the 24 locales — because this mail
 * is sent for work that was never published through the platform and never
 * checked by anything.
 *
 * No database: the mailable takes scalars only.
 */

/**
 * @return array{subject: string, body: string}
 */
function renderCompletedOnApproval(AssignmentCompletedOnApprovalMail $mail, string $locale): array
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

function completedOnApprovalMail(): AssignmentCompletedOnApprovalMail
{
    return new AssignmentCompletedOnApprovalMail(
        creatorName: 'Maria',
        campaignName: 'Autumn UGC push',
        assignmentUlid: '01JASSIGNMENTULID',
    );
}

it('renders the subject, the body and the assignment deep link', function (): void {
    $rendered = renderCompletedOnApproval(completedOnApprovalMail(), 'en');

    expect($rendered['subject'])->toBe('Your work for Autumn UGC push is complete')
        ->and($rendered['body'])->toContain('Maria')
        ->and($rendered['body'])->toContain('Autumn UGC push')
        ->and($rendered['body'])->toContain('has been approved')
        ->and($rendered['body'])->toContain('nothing further for you to do')
        // The emitted URL, pinned — a mail whose button goes nowhere is worse
        // than no mail.
        ->and($rendered['body'])->toContain('/creator/assignments/01JASSIGNMENTULID');
});

it('never claims the content was posted or verified', function (): void {
    // The copy constraint, asserted rather than trusted to review. Nothing was
    // published through the platform and no verification ran.
    $rendered = renderCompletedOnApproval(completedOnApprovalMail(), 'en');
    $body = strtolower($rendered['body']);

    expect($body)->not->toContain('verified')
        ->and($body)->not->toContain('verification')
        // "posted" would tell the creator their content is live somewhere.
        ->and($body)->not->toContain('posted');
});

it('carries no round number — this mail is about the assignment ending', function (): void {
    // Deliberate absence (the AH-068 mailables both carry a round). Naming one
    // here would invite the reader to expect another.
    $rendered = renderCompletedOnApproval(completedOnApprovalMail(), 'en');

    expect($rendered['subject'])->not->toContain('Draft ')
        ->and($rendered['body'])->not->toContain('Draft ');
});

it('renders in a non-English locale with the interpolated values intact', function (string $locale): void {
    $mail = completedOnApprovalMail();
    $en = renderCompletedOnApproval($mail, 'en');
    $translated = renderCompletedOnApproval($mail, $locale);

    expect($translated['subject'])->not->toBe('')
        ->and($translated['subject'])->not->toBe($en['subject'])
        ->and($translated['body'])->not->toBe($en['body'])
        ->and($translated['body'])->toContain('Autumn UGC push')
        ->and($translated['body'])->toContain('Maria')
        ->and($translated['body'])->toContain('/creator/assignments/01JASSIGNMENTULID');
})->with(['pt', 'it', 'fr', 'de', 'pl', 'nl']);

it('renders in the flaky-10 locales with real translations, not the English fallback', function (string $locale): void {
    // The flaky 10 are where machine-translation baselines have gone missing
    // before (AH-028/046/047). Every key in this mail is new in all 24.
    $mail = completedOnApprovalMail();
    $en = renderCompletedOnApproval($mail, 'en');
    $translated = renderCompletedOnApproval($mail, $locale);

    expect($translated['subject'])->not->toBe($en['subject'])
        ->and($translated['body'])->not->toBe($en['body']);
})->with(['bg', 'el', 'et', 'fi', 'ga', 'hu', 'lt', 'lv', 'mt', 'ro']);

it('renders a translated body in every one of the 23 non-English locales', function (): void {
    // `hr`/`sk`/`sl` are included deliberately — Czech text bled into their SPA
    // draft keys once (AH-046's class).
    $mail = completedOnApprovalMail();
    $en = renderCompletedOnApproval($mail, 'en')['body'];

    foreach (['bg', 'cs', 'da', 'de', 'el', 'es', 'et', 'fi', 'fr', 'ga', 'hr', 'hu', 'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv'] as $locale) {
        expect(renderCompletedOnApproval($mail, $locale)['body'])
            ->not->toBe($en, "locale {$locale} rendered the English body");
    }
});
