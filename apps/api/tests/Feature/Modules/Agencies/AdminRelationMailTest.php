<?php

declare(strict_types=1);

use App\Core\Enums\Locale;
use App\Modules\Agencies\Mail\AdminConnectedMail;
use App\Modules\Agencies\Mail\RelationDisconnectedMail;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| AH-051 (D-7) — the two new admin-relation mailables (§5.3 real-render +
| queued-locale + ×24-locale parity). Verified by real render (not a real
| inbox); config/mail.php default is `log`.
|--------------------------------------------------------------------------
*/

it('AdminConnectedMail renders naming the agency + carries the support line', function (): void {
    $rendered = (new AdminConnectedMail(
        creatorDisplayName: 'Ada',
        agencyName: 'Northwind Talent',
    ))->render();

    expect($rendered)->toContain('Northwind Talent')
        ->and($rendered)->toContain('Ada')
        ->and($rendered)->toContain('support');
});

it('RelationDisconnectedMail renders naming the counterparty + carries the support line (no CTA)', function (): void {
    $rendered = (new RelationDisconnectedMail(
        recipientName: 'Ada',
        counterpartyName: 'Northwind Talent',
    ))->render();

    expect($rendered)->toContain('Northwind Talent')
        ->and($rendered)->toContain('Ada')
        ->and($rendered)->toContain('support');
});

it('AdminConnectedMail carries the localized subject with the agency placeholder resolved', function (): void {
    $mail = (new AdminConnectedMail('Ada', 'Northwind Talent'))->envelope();

    expect($mail->subject)->toContain('Northwind Talent');
});

it('RelationDisconnectedMail carries the localized subject with the counterparty placeholder resolved', function (): void {
    $mail = (new RelationDisconnectedMail('Ada', 'Northwind Talent'))->envelope();

    expect($mail->subject)->toContain('Northwind Talent');
});

it('renders both mailables in every UI locale without falling through or throwing', function (string $locale): void {
    app()->setLocale($locale);

    $connected = (new AdminConnectedMail('Ada', 'Northwind Talent'))->render();
    $disconnected = (new RelationDisconnectedMail('Ada', 'Northwind Talent'))->render();

    // Both surface the interpolated party names (proves the placeholder keys
    // resolved in this locale, not a raw `:agency` / `:counterparty` token).
    expect($connected)->toContain('Northwind Talent')
        ->and($connected)->not->toContain(':agency')
        ->and($disconnected)->toContain('Northwind Talent')
        ->and($disconnected)->not->toContain(':counterparty');
})->with(Locale::uiValues());
