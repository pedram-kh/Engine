<?php

declare(strict_types=1);

use App\Modules\Campaigns\Enums\ApplicationRejectionCause;
use App\Modules\Campaigns\Mail\ApplicationAcceptedMail;
use App\Modules\Campaigns\Mail\ApplicationRejectedMail;
use App\Modules\Campaigns\Mail\ApplicationSubmittedMail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * The mailable's OWN parameters (its promoted constructor properties), excluding
 * everything inherited from Mailable. What a mailable can be told is what it can
 * put in an inbox, so two of the tests below assert over this list.
 *
 * @return list<string>
 */
function applicationMailParameters(Mailable $mail): array
{
    $constructor = (new ReflectionClass($mail))->getConstructor();

    return array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        $constructor === null ? [] : $constructor->getParameters(),
    );
}

uses(TestCase::class);

/**
 * §5.3 REAL-RENDER tests for the three application mails (AH-058, D6).
 *
 * `Mail::fake()` in the endpoint specs proves the right mailable was queued to
 * the right person; it renders nothing, so a broken Blade partial or a missing
 * locale value would sail past it and reach a live creator — or, for the
 * `submitted` verb, every admin and manager of an agency — as a stack trace.
 * These render for real, in several locales, and pin the deep-link shapes so a
 * route rename becomes a red test instead of a 404 in an inbox.
 *
 * No database: all three mailables take scalars only (the fan-out + queued-worker
 * reason is in their docblocks), which is exactly what makes them renderable in
 * isolation.
 */

/**
 * Render a mailable's subject + HTML body inside a given locale.
 * `Mailable::locale()` defers the real switch to send time, so a direct render
 * needs an explicit App locale — the job-posted / incomplete-nudge precedent.
 *
 * @return array{subject: string, body: string}
 */
function renderApplicationMail(Mailable $mail, string $locale): array
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

function submittedMail(): ApplicationSubmittedMail
{
    return new ApplicationSubmittedMail(
        recipientName: 'Dana',
        creatorName: 'Maria',
        campaignName: 'Autumn UGC push',
        actionUrl: 'https://spa.test/campaigns/01JCAMPAIGNULID',
    );
}

function acceptedMail(): ApplicationAcceptedMail
{
    return new ApplicationAcceptedMail(
        creatorName: 'Maria',
        agencyName: 'Bright Harbour',
        campaignName: 'Autumn UGC push',
        actionUrl: 'https://spa.test/creator/assignments/01JASSIGNMENTULID',
    );
}

function rejectedMail(ApplicationRejectionCause $cause): ApplicationRejectedMail
{
    return new ApplicationRejectedMail(
        creatorName: 'Maria',
        campaignName: 'Autumn UGC push',
        cause: $cause,
        actionUrl: 'https://spa.test/creator/jobs',
    );
}

it('submitted: renders the applicant, the campaign and the campaign deep link', function (): void {
    $rendered = renderApplicationMail(submittedMail(), 'en');

    expect($rendered['subject'])->toBe('New application for Autumn UGC push')
        ->and($rendered['body'])->toContain('Dana')
        ->and($rendered['body'])->toContain('Maria')
        ->and($rendered['body'])->toContain('Autumn UGC push')
        ->and($rendered['body'])->toContain('/campaigns/01JCAMPAIGNULID');
});

it('submitted: carries NO application note — free text stays behind the agency surface', function (): void {
    // The note is what the creator wrote for the agency's review surface, and an
    // email can be forwarded outside it. The mailable has no note parameter at
    // all, which is the strongest form of this guarantee; this test pins that the
    // constructor signature is not quietly widened.
    expect(applicationMailParameters(submittedMail()))
        ->toBe(['recipientName', 'creatorName', 'campaignName', 'actionUrl']);
});

it('accepted: says review the OFFER — an accepted application is an invitation, not an engagement', function (): void {
    $rendered = renderApplicationMail(acceptedMail(), 'en');

    expect($rendered['subject'])->toBe('Your application for Autumn UGC push was accepted')
        ->and($rendered['body'])->toContain('Bright Harbour')
        ->and($rendered['body'])->toContain('Autumn UGC push')
        // The wording is a product constraint (D2): the creator still accepts or
        // declines the real offer, so the copy must not read as a commitment.
        ->and($rendered['body'])->toContain('accept or decline')
        // The deep link goes to the ASSIGNMENT — the offer needing an answer.
        ->and($rendered['body'])->toContain('/creator/assignments/01JASSIGNMENTULID');
});

it('rejected: one subject, two bodies, selected by the cause', function (): void {
    $agency = renderApplicationMail(rejectedMail(ApplicationRejectionCause::AgencyRejected), 'en');
    $closed = renderApplicationMail(rejectedMail(ApplicationRejectionCause::CampaignClosed), 'en');

    // Shared subject (D5 / Q8) — the recipient's question is the same either way.
    expect($agency['subject'])->toBe('An update on your application for Autumn UGC push')
        ->and($closed['subject'])->toBe($agency['subject'])
        // …and genuinely different bodies. A cause that silently rendered the
        // same sentence would make the enum decorative.
        ->and($agency['body'])->not->toBe($closed['body'])
        ->and($agency['body'])->toContain('not selected')
        ->and($closed['body'])->toContain('closed')
        ->and($agency['body'])->toContain('/creator/jobs')
        ->and($closed['body'])->toContain('/creator/jobs');
});

it('rejected: carries no reason field and no rejection detail (D4)', function (): void {
    // D4 refuses an agency-supplied reason: none is collected, stored or
    // rendered. The mailable has no parameter for one.
    expect(applicationMailParameters(rejectedMail(ApplicationRejectionCause::AgencyRejected)))
        ->toBe(['creatorName', 'campaignName', 'cause', 'actionUrl']);
});

it('renders every one of the three in a non-English locale, values intact', function (string $locale): void {
    foreach ([submittedMail(), acceptedMail(), rejectedMail(ApplicationRejectionCause::CampaignClosed)] as $mail) {
        $en = renderApplicationMail($mail, 'en');
        $translated = renderApplicationMail($mail, $locale);

        expect($translated['subject'])->not->toBe('')
            ->and($translated['subject'])->not->toBe($en['subject'])
            ->and($translated['body'])->not->toBe($en['body'])
            // Interpolated values must survive translation.
            ->and($translated['body'])->toContain('Autumn UGC push')
            ->and($translated['body'])->toContain('Maria');
    }
})->with(['pt', 'it', 'fr', 'de', 'pl', 'nl']);

it('renders in the flaky-10 locales with real translations, not the English fallback', function (string $locale): void {
    // The flaky 10 are where machine-translation baselines have gone missing
    // before (AH-028/046/047). New keys never ship with an English fallback, so
    // every one of these must differ from en — subject AND body.
    foreach ([submittedMail(), acceptedMail(), rejectedMail(ApplicationRejectionCause::AgencyRejected)] as $mail) {
        $en = renderApplicationMail($mail, 'en');
        $translated = renderApplicationMail($mail, $locale);

        expect($translated['subject'])->not->toBe($en['subject'])
            ->and($translated['body'])->not->toBe($en['body']);
    }
})->with(['bg', 'el', 'et', 'fi', 'ga', 'hu', 'lt', 'lv', 'mt', 'ro']);

it('tags all three as transactional campaign traffic', function (): void {
    expect(submittedMail()->envelope()->tags)->toBe(['campaigns', 'application-submitted'])
        ->and(acceptedMail()->envelope()->tags)->toBe(['campaigns', 'application-accepted'])
        ->and(rejectedMail(ApplicationRejectionCause::AgencyRejected)->envelope()->tags)
        ->toBe(['campaigns', 'application-rejected']);
});
