<?php

declare(strict_types=1);

use App\Modules\Campaigns\Mail\OfferEditedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * §5.3 REAL-RENDER test for the offer-edited mail (AH-118): the template is
 * rendered for real, in every shipped locale. `Mail::fake()` elsewhere proves
 * it was queued to the right person and renders nothing, so a missing locale
 * value would reach an inbox as a raw translation key.
 */

/**
 * @return array{subject: string, body: string}
 */
function renderOfferEdited(string $locale): array
{
    $mail = new OfferEditedMail(
        creatorName: 'Maria',
        campaignName: 'Autumn UGC push',
        assignmentUlid: '01HZZZZZZZZZZZZZZZZZZZZZZZ',
        previousAmount: '100.00 EUR',
        newAmount: '250.00 EUR',
    );

    $previous = App::getLocale();
    App::setLocale($locale);

    try {
        return ['subject' => $mail->envelope()->subject ?? '', 'body' => (string) $mail->render()];
    } finally {
        App::setLocale($previous);
    }
}

/**
 * @return array<string, array{string}>
 */
function offerEditedLocales(): array
{
    $out = [];
    foreach (glob(dirname(__DIR__, 4).'/lang/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $out[basename($dir)] = [basename($dir)];
    }

    return $out;
}

it('renders the campaign, both amounts and the deep link in every shipped locale', function (string $locale): void {
    $rendered = renderOfferEdited($locale);

    expect($rendered['subject'])->toContain('Autumn UGC push')
        ->and($rendered['body'])->toContain('Autumn UGC push')
        ->and($rendered['body'])->toContain('100.00 EUR')
        ->and($rendered['body'])->toContain('250.00 EUR')
        ->and($rendered['body'])->toContain('Maria')
        // The creator SPA route; drift is a red test rather than a 404 in an inbox.
        ->and($rendered['body'])->toContain('/creator/assignments/01HZZZZZZZZZZZZZZZZZZZZZZZ')
        // A missing translation renders its own key.
        ->and($rendered['subject'])->not->toContain('assignment_notifications')
        ->and($rendered['body'])->not->toContain('assignment_notifications')
        ->and($rendered['body'])->not->toContain(':previous')
        ->and($rendered['body'])->not->toContain(':new');
})->with(offerEditedLocales());

it('is translated, not English, in every locale but en (the flaky ten included)', function (string $locale): void {
    if ($locale === 'en') {
        expect(renderOfferEdited('en')['subject'])->toBe('The offer for Autumn UGC push was updated');

        return;
    }

    $en = renderOfferEdited('en');
    $translated = renderOfferEdited($locale);

    expect($translated['subject'])->not->toBe($en['subject'])
        ->and($translated['body'])->not->toBe($en['body']);
})->with(offerEditedLocales());

it('ships all 24 locales', function (): void {
    expect(offerEditedLocales())->toHaveCount(24);
});
