<?php

declare(strict_types=1);

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Campaigns\Mail\JobPostedMail;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * §5.3 REAL-RENDER tests for the job-posted mail (AH-056, D6).
 *
 * `Mail::fake()` in the fan-out spec proves the mailable was queued to the
 * right person; it renders nothing, so a broken Blade partial or a missing
 * locale value would sail past it and land in ~279 inboxes as a stack trace.
 * These render the template for real, in several locales, and check the deep
 * link is actually in the body.
 */

/**
 * Render a mailable's subject + HTML body inside a given locale.
 * `Mailable::locale()` defers the real switch to send time, so a direct render
 * needs an explicit App locale — the incomplete-nudge precedent.
 *
 * @return array{subject: string, body: string}
 */
function renderJobPosted(JobPostedMail $mail, string $locale): array
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

function jobPostedMail(): array
{
    $agency = Agency::factory()->createOne(['name' => 'Bright Harbour']);
    $campaign = Campaign::factory()->listed()->createOne([
        'agency_id' => $agency->id,
        'name' => 'Autumn UGC push',
    ]);
    $user = User::factory()->creator()->createOne(['name' => 'Maria']);

    $base = rtrim((string) config('app.frontend_main_url'), '/');
    $url = $base.'/creator/jobs/'.$campaign->ulid;

    return [
        new JobPostedMail(user: $user, campaign: $campaign, actionUrl: $url),
        $url,
        $campaign,
    ];
}

it('renders a distinct subject per locale, carrying the agency name', function (): void {
    [$mail] = jobPostedMail();

    $en = renderJobPosted($mail, 'en')['subject'];
    $pt = renderJobPosted($mail, 'pt')['subject'];
    $it = renderJobPosted($mail, 'it')['subject'];

    expect($en)->toBe('Bright Harbour posted a new job')
        ->and($en)->not->toBe($pt)
        ->and($en)->not->toBe($it)
        ->and($pt)->not->toBe($it);
});

it('renders a real body with the campaign name, the agency name and the deep link', function (): void {
    [$mail, $url, $campaign] = jobPostedMail();

    $body = renderJobPosted($mail, 'en')['body'];

    expect($body)->toContain('Autumn UGC push')
        ->and($body)->toContain('Bright Harbour')
        ->and($body)->toContain('Maria')
        // The deep link shape: drift from /creator/jobs/{ulid} — which is the
        // creator SPA route — is a red test rather than a 404 in an inbox.
        ->and($body)->toContain('/creator/jobs/'.$campaign->ulid)
        ->and($body)->toContain($url);
});

it('renders the body differently in every rendered locale', function (string $locale): void {
    [$mail] = jobPostedMail();

    $en = renderJobPosted($mail, 'en')['body'];
    $translated = renderJobPosted($mail, $locale)['body'];

    expect($translated)->not->toBe('')
        ->and($translated)->not->toBe($en)
        // The interpolated values must survive translation.
        ->and($translated)->toContain('Autumn UGC push')
        ->and($translated)->toContain('Bright Harbour');
})->with(['pt', 'it', 'fr', 'de']);

it('tags the mail as transactional campaign traffic', function (): void {
    [$mail] = jobPostedMail();

    expect($mail->envelope()->tags)->toBe(['campaigns', 'job-posted']);
});

it('carries NO brand identity — the D3 subset is board content, not inbox content', function (): void {
    $agency = Agency::factory()->createOne(['name' => 'Bright Harbour']);
    $brand = Brand::factory()->forAgency($agency->id)->createOne([
        'name' => 'Northwind Coffee',
    ]);
    $campaign = Campaign::factory()->listed()->createOne([
        'agency_id' => $agency->id,
        'brand_id' => $brand->id,
        'name' => 'Autumn UGC push',
    ]);
    $user = User::factory()->creator()->createOne(['name' => 'Maria']);

    $mail = new JobPostedMail(user: $user, campaign: $campaign, actionUrl: 'https://example.test/creator/jobs/x');

    // An email lands in an inbox and can be forwarded; it is not behind the
    // visibility predicate the board is behind. The brand's identity waits
    // until the creator opens the job.
    expect(renderJobPosted($mail, 'en')['body'])->not->toContain('Northwind Coffee');
});
