<?php

declare(strict_types=1);

use App\Modules\Creators\Enums\IncompleteCreatorNudgeVariant;
use App\Modules\Creators\Mail\IncompleteCreatorNudgeMail;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * §5.3 real-rendering tests for the incomplete-creator nudge mail — BOTH
 * variants actually render (not just Mail::fake()), across en/pt/it, so a
 * broken Blade template or a missing locale value reds here.
 *
 * The verify variant additionally asserts the full verify-link shape appears
 * in the rendered body (the Q2 guard): any future drift between the nudge
 * service's local URL build and SignUpService::buildVerifyUrl() is a red test,
 * not a silent 404.
 */

/**
 * Render a mailable's subject + HTML body inside a given locale. Mailable::locale()
 * defers the real switch to send-time; direct render needs an explicit App locale.
 *
 * @return array{subject: string, body: string}
 */
function renderNudgeLocale(IncompleteCreatorNudgeMail $mail, string $locale): array
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

function nudgeUser(): User
{
    return User::factory()->creator()->create(['name' => 'Maria']);
}

// -----------------------------------------------------------------------------
// Verify variant.
// -----------------------------------------------------------------------------

it('renders the VERIFY variant subject in en/pt/it as distinct strings', function (): void {
    $mail = new IncompleteCreatorNudgeMail(
        user: nudgeUser(),
        variant: IncompleteCreatorNudgeVariant::Verify,
        actionUrl: 'https://example.test/auth/verify-email?token=x',
        expiresInHours: 24,
    );

    $en = renderNudgeLocale($mail, 'en')['subject'];
    $pt = renderNudgeLocale($mail, 'pt')['subject'];
    $it = renderNudgeLocale($mail, 'it')['subject'];

    expect($en)->not->toBe($pt)
        ->and($en)->not->toBe($it)
        ->and($pt)->not->toBe($it)
        ->and($en)->not->toBe('');
});

it('renders the VERIFY variant body per locale AND embeds the full verify-link shape (Q2 guard)', function (): void {
    $base = rtrim((string) config('app.frontend_main_url'), '/');
    $verifyUrl = $base.'/auth/verify-email?token=sample-token';

    $mail = new IncompleteCreatorNudgeMail(
        user: nudgeUser(),
        variant: IncompleteCreatorNudgeVariant::Verify,
        actionUrl: $verifyUrl,
        expiresInHours: 24,
    );

    $en = renderNudgeLocale($mail, 'en')['body'];
    $pt = renderNudgeLocale($mail, 'pt')['body'];

    // The verify-link shape must appear in the rendered body — drift from
    // {frontend_main_url}/auth/verify-email?token= is a red test.
    expect($en)->toContain($base.'/auth/verify-email?token=')
        ->and($en)->toContain($verifyUrl)
        ->and($en)->not->toBe($pt);
});

// -----------------------------------------------------------------------------
// Finish variant.
// -----------------------------------------------------------------------------

it('renders the FINISH variant subject in en/pt/it as distinct strings', function (): void {
    $mail = new IncompleteCreatorNudgeMail(
        user: nudgeUser(),
        variant: IncompleteCreatorNudgeVariant::Finish,
        actionUrl: 'https://example.test/onboarding',
    );

    $en = renderNudgeLocale($mail, 'en')['subject'];
    $pt = renderNudgeLocale($mail, 'pt')['subject'];
    $it = renderNudgeLocale($mail, 'it')['subject'];

    expect($en)->not->toBe($pt)
        ->and($en)->not->toBe($it)
        ->and($pt)->not->toBe($it)
        ->and($en)->not->toBe('');
});

it('renders the FINISH variant body with the /onboarding deep link', function (): void {
    $base = rtrim((string) config('app.frontend_main_url'), '/');
    $finishUrl = $base.'/onboarding';

    $mail = new IncompleteCreatorNudgeMail(
        user: nudgeUser(),
        variant: IncompleteCreatorNudgeVariant::Finish,
        actionUrl: $finishUrl,
    );

    $en = renderNudgeLocale($mail, 'en')['body'];
    $it = renderNudgeLocale($mail, 'it')['body'];

    expect($en)->toContain($finishUrl)
        ->and($en)->not->toBe($it);
});
