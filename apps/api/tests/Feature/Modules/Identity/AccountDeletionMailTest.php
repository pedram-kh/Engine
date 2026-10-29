<?php

declare(strict_types=1);

use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Events\AccountDeletionRequested;
use App\Modules\Identity\Features\AccountDeletionEnabled;
use App\Modules\Identity\Mail\AccountDeletionRequestedMail;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;
use Tests\Support\ErasureGraph;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-104b-del A6 — the confirmation mail
|--------------------------------------------------------------------------
|
| One mail per accepted request, queued after the commit, in the person's
| language, on the same flag as the endpoint; no link in it that changes the
| account. Rendered for real in all 24 locales: a missing key in one locale
| would otherwise reach an inbox as a key name.
*/

const DM_PASSWORD = 'password-12chars';

const DM_LOCALES = [
    'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'ga', 'hr', 'hu',
    'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv',
];

beforeEach(function (): void {
    Cache::flush();
    Feature::activate(AccountDeletionEnabled::NAME);
});

function dmCreator(string $language = 'es', string $timezone = 'Europe/Madrid'): User
{
    $user = User::factory()->creator()->createOne([
        'email' => 'mail-me@example.test',
        'name' => 'Ana',
        'preferred_language' => $language,
        'timezone' => $timezone,
    ]);
    Creator::factory()->createOne(['user_id' => $user->id]);

    return $user->refresh();
}

/** @return array{subject: string, body: string} */
function dmRender(AccountDeletionRequestedMail $mail, string $locale): array
{
    $previous = App::getLocale();
    App::setLocale($locale);

    try {
        return ['subject' => $mail->envelope()->subject ?? '', 'body' => (string) $mail->render()];
    } finally {
        App::setLocale($previous);
    }
}

// -----------------------------------------------------------------------------
// When it is sent
// -----------------------------------------------------------------------------

it('queues exactly one mail to the address on the account, in the person\'s language, after a request', function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-11-02 10:00:00');
    $user = dmCreator('es');

    $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', ['password' => DM_PASSWORD, 'acknowledged' => true])->assertOk();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(AccountDeletionRequestedMail::class, function (AccountDeletionRequestedMail $mail): bool {
        return $mail->hasTo('mail-me@example.test')
            && $mail->locale === 'es'
            && $mail->recipientName === 'Ana'
            && $mail->scheduledFor->toIso8601String() === '2026-11-16T10:00:00+00:00'
            && $mail->timezone === 'Europe/Madrid';
    });
});

it('sends nothing when the request is blocked up front', function (): void {
    Mail::fake();
    $graph = ErasureGraph::seedSubject('mail');
    $graph->user->forceFill(['password' => DM_PASSWORD])->saveQuietly();
    $id = DB::table('campaign_assignments')->where('creator_id', $graph->creator->id)->value('id');
    DB::table('campaign_assignments')->where('id', $id)->update(['status' => 'payment_held']);

    $this->actingAs($graph->user->refresh(), 'web')->postJson('/api/v1/me/account-deletion', ['password' => DM_PASSWORD, 'acknowledged' => true])->assertStatus(422);

    Mail::assertNothingQueued();
});

it('sends nothing for a wrong password or a missing acknowledgement', function (): void {
    Mail::fake();
    $user = dmCreator();

    $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', ['password' => 'nope', 'acknowledged' => true])->assertForbidden();
    $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', ['password' => DM_PASSWORD])->assertStatus(422);

    Mail::assertNothingQueued();
});

it('sends one mail, not two, when the same request is made twice', function (): void {
    Mail::fake();
    $user = dmCreator();

    $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', ['password' => DM_PASSWORD, 'acknowledged' => true])->assertOk();
    $this->actingAs($user->refresh(), 'web')->postJson('/api/v1/me/account-deletion', ['password' => DM_PASSWORD, 'acknowledged' => true])->assertOk();

    Mail::assertQueuedCount(1);
});

it('sends nothing when the commit is rolled back', function (): void {
    Mail::fake();
    $user = dmCreator();
    Schema::drop('audit_logs');

    $this->actingAs($user, 'web')->postJson('/api/v1/me/account-deletion', ['password' => DM_PASSWORD, 'acknowledged' => true])->assertStatus(500);

    Mail::assertNothingQueued();
});

it('is silent when the flag is off, even for an event already on its way', function (): void {
    Mail::fake();
    $user = dmCreator();
    Feature::deactivate(AccountDeletionEnabled::NAME);

    Event::dispatch(new AccountDeletionRequested($user, now()->addDays(14)));

    Mail::assertNothingQueued();
});

it('is a queued mail, so a slow mail server never holds the request', function (): void {
    expect(new AccountDeletionRequestedMail('Ana', now()))->toBeInstanceOf(ShouldQueue::class);
});

// -----------------------------------------------------------------------------
// What it says
// -----------------------------------------------------------------------------

it('renders for real in all 24 locales with the name, the date, the way to keep the account and the support address', function (): void {
    expect(DM_LOCALES)->toHaveCount(24);
    config()->set('app.support_email', 'help@support.example');
    $mail = new AccountDeletionRequestedMail('Ana', Carbon::parse('2026-11-16 10:00:00', 'UTC'), 'Europe/Madrid');

    foreach (DM_LOCALES as $locale) {
        $rendered = dmRender($mail, $locale);

        expect($rendered['subject'])->not->toBe('', "{$locale}: empty subject");
        expect($rendered['subject'])->not->toContain('auth.deletion_mail');
        expect($rendered['body'])->toContain('Ana');
        expect($rendered['body'])->toContain('help@support.example');
        expect($rendered['body'])->not->toContain('auth.deletion_mail');
        expect($rendered['body'])->not->toContain(':app');
        expect($rendered['body'])->not->toContain(':date');
        expect($rendered['body'])->not->toContain(':support');
        expect($rendered['body'])->toMatch('/2026/', "{$locale}: the date is missing");
    }
});

it('writes the date in the person\'s language and timezone', function (): void {
    $instant = Carbon::parse('2026-11-16 23:30:00', 'UTC');

    $en = dmRender(new AccountDeletionRequestedMail('Ana', $instant, 'UTC'), 'en')['body'];
    $es = dmRender(new AccountDeletionRequestedMail('Ana', $instant, 'UTC'), 'es')['body'];
    // 23:30 UTC is already the 17th in Tokyo.
    $tokyo = dmRender(new AccountDeletionRequestedMail('Ana', $instant, 'Asia/Tokyo'), 'en')['body'];
    $bogus = dmRender(new AccountDeletionRequestedMail('Ana', $instant, 'Not/AZone'), 'en')['body'];

    expect($en)->toContain('November 16, 2026')
        ->and($es)->toContain('16 de noviembre de 2026')
        // AH-113: the date is "after", never the day of the erasure.
        ->and($es)->toContain('después del 16 de noviembre de 2026')
        ->and($en)->not->toMatch('/deleted on/i')
        ->and($tokyo)->toContain('November 17, 2026')
        ->and($bogus)->toContain('November 16, 2026');
});

it('says how to keep the account and what to do if it was not them, and names no link that changes anything', function (): void {
    $body = dmRender(new AccountDeletionRequestedMail('Ana', Carbon::parse('2026-11-16 10:00:00'), 'UTC'), 'en')['body'];

    expect($body)->toContain('permanently deleted after November 16, 2026')
        ->and($body)->toContain('sign in with your email and password before that date')
        ->and($body)->toContain('If you did not ask for this');

    // The only links are the theme's own (header/footer); none is built from the account or the request.
    preg_match_all('/href="([^"]*)"/', $body, $m);
    foreach ($m[1] as $href) {
        expect($href)->not->toMatch('/cancel|restore|delete|token|signature|expires/i');
    }
});

it('takes the support address from config and from nowhere else', function (): void {
    config()->set('app.support_email', 'first@support.example');
    $first = dmRender(new AccountDeletionRequestedMail('Ana', now(), 'UTC'), 'en')['body'];
    config()->set('app.support_email', 'second@support.example');
    $second = dmRender(new AccountDeletionRequestedMail('Ana', now(), 'UTC'), 'en')['body'];

    expect($first)->toContain('first@support.example')->and($first)->not->toContain('second@support.example')
        ->and($second)->toContain('second@support.example');
});
