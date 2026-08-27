<?php

declare(strict_types=1);

use App\Modules\Identity\Mail\MfaExemptionGrantedMail;
use App\Modules\Identity\Mail\MfaExemptionRemovedMail;
use App\Modules\Identity\Mail\MfaResetByAdminMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

uses(TestCase::class);

/**
 * §5.3 REAL-RENDER tests for the three admin-initiated 2FA notices (AH-087).
 *
 * `Mail::fake()` in the endpoint specs proves the right mailable was queued to
 * the right person; it renders nothing. These render each template for real in
 * all 24 shipped locales, because a missing translation key or a broken Blade
 * partial in one locale would otherwise reach an inbox as a stack trace — and
 * these particular mails go to someone whose account security just changed.
 */
const AH087_MAIL_LOCALES = [
    'bg', 'cs', 'da', 'de', 'el', 'es', 'et', 'fi', 'fr', 'ga', 'hr', 'hu',
    'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv',
];

/**
 * `Mailable::locale()` defers the real switch to send time, so a direct render
 * needs an explicit App locale — the incomplete-nudge precedent.
 *
 * The parameter is the union of the three concrete classes rather than the base
 * `Mailable`: `envelope()` is a Laravel convention on the subclass, not a method
 * the base class declares, so a widened type here would not type-check.
 *
 * @return array{subject: string, body: string}
 */
function ah087RenderNotice(MfaResetByAdminMail|MfaExemptionGrantedMail|MfaExemptionRemovedMail $mail, string $locale): array
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
 * @return array<string, MfaResetByAdminMail|MfaExemptionGrantedMail|MfaExemptionRemovedMail>
 */
function ah087Notices(): array
{
    return [
        'reset' => new MfaResetByAdminMail(recipientName: 'Ana'),
        'exempted' => new MfaExemptionGrantedMail(recipientName: 'Ana'),
        'exemption_removed' => new MfaExemptionRemovedMail(recipientName: 'Ana'),
    ];
}

it('renders a real body carrying the recipient name in English', function (): void {
    foreach (ah087Notices() as $key => $mail) {
        $rendered = ah087RenderNotice($mail, 'en');

        expect($rendered['subject'])->not->toBe('', "{$key} rendered an empty subject");
        expect($rendered['body'])->toContain('Ana')
            ->and($rendered['body'])->not->toContain('auth.mfa_admin');
    }
});

it('tells a reset recipient what happened, what is next, and what to do if it was not them', function (): void {
    $body = ah087RenderNotice(new MfaResetByAdminMail(recipientName: 'Ana'), 'en')['body'];

    expect($body)->toContain('reset two-factor authentication')
        // The re-enrollment expectation — without it the next 2FA prompt reads
        // as a bug rather than as the documented consequence.
        ->and($body)->toContain('set two-factor authentication up again')
        // The takeover tripwire: the same operation a support request produces
        // is what an account takeover would look like.
        ->and($body)->toContain('contact support immediately')
        // The agency-branded door (AH-086b) is where re-enrollment starts.
        ->and($body)->toContain('/agency/sign-in');
});

it('tells an exempted recipient that an existing enrollment still challenges them at sign-in', function (): void {
    $body = ah087RenderNotice(new MfaExemptionGrantedMail(recipientName: 'Ana'), 'en')['body'];

    expect($body)->toContain('removed the two-factor authentication requirement')
        // R6: the enrolled-and-exempt case is stated outright, because being
        // asked for a code after an "exemption" email is correct behaviour.
        ->and($body)->toContain('it stays active and you will still be asked for a code');
});

it('tells an un-exempted recipient the requirement is back', function (): void {
    $body = ah087RenderNotice(new MfaExemptionRemovedMail(recipientName: 'Ana'), 'en')['body'];

    expect($body)->toContain('restored the two-factor authentication requirement')
        ->and($body)->toContain('you will be asked to set two-factor authentication up');
});

it('never names an acting administrator or quotes a reason — there is no parameter for either', function (): void {
    foreach (ah087Notices() as $key => $mail) {
        $body = ah087RenderNotice($mail, 'en')['body'];

        // The AH-051 eyes-on discipline, enforced at the type level: these
        // mailables take a recipient name and nothing else, so there is no
        // admin identity or rationale available to leak into the copy.
        expect($body)->not->toContain('Reason:')
            ->and($body)->not->toContain('administrator (');
    }
});

it('renders a distinct, non-empty body in every shipped locale', function (string $locale): void {
    foreach (ah087Notices() as $key => $mail) {
        $en = ah087RenderNotice($mail, 'en');
        $translated = ah087RenderNotice($mail, $locale);

        expect($translated['body'])->not->toBe('', "locale {$locale} rendered an empty {$key} body")
            ->and($translated['body'])->not->toBe($en['body'], "locale {$locale} rendered the English {$key} body")
            ->and($translated['subject'])->not->toBe($en['subject'], "locale {$locale} rendered the English {$key} subject")
            // The recipient name must survive translation, and no raw key may
            // appear because a locale is missing a string.
            ->and($translated['body'])->toContain('Ana')
            ->and($translated['body'])->not->toContain('auth.mfa_admin');
    }
})->with(AH087_MAIL_LOCALES);

it('tags each notice as auth traffic with its own distinguishable tag', function (): void {
    expect((new MfaResetByAdminMail(recipientName: 'Ana'))->envelope()->tags)
        ->toBe(['auth', 'mfa-admin-reset']);
    expect((new MfaExemptionGrantedMail(recipientName: 'Ana'))->envelope()->tags)
        ->toBe(['auth', 'mfa-admin-exempted']);
    expect((new MfaExemptionRemovedMail(recipientName: 'Ana'))->envelope()->tags)
        ->toBe(['auth', 'mfa-admin-exemption-removed']);
});

it('queues rather than sending inline — the three notices ride the worker', function (): void {
    foreach (ah087Notices() as $key => $mail) {
        expect($mail)->toBeInstanceOf(ShouldQueue::class, "{$key} is not queued");
    }
});
