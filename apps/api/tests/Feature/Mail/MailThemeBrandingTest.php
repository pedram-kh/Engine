<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Mail\InviteAgencyUserMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Creators\Mail\ProspectCreatorInviteMail;
use App\Modules\Identity\Mail\ResetPasswordMail;
use App\Modules\Identity\Mail\VerifyEmailMail;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/*
 * Real-rendering brand-mail-theme verification (Sprint 3.5 Chunk 4,
 * Workstream C; standing standard 5.3 "real-rendering mailable test").
 *
 * Markdown mail clients do not resolve CSS custom properties, so the
 * `catalyst` theme (config/mail.php `markdown.theme`) inlines BRAND HEX
 * LITERALS into the rendered HTML via css-to-inline-styles. These tests
 * render each product mailable end-to-end (`$mail->render()`, transport-
 * independent — no send) and assert the brand teal-700 (#0b6f66) button
 * colour appears AND the stock Laravel default button colour (#2d3748) is
 * gone — i.e. the catalyst theme is actually applied, not the default.
 */

const BRAND_TEAL_700 = '#0b6f66';
const STOCK_DEFAULT_BUTTON = '#2d3748';

it('inlines the brand teal mail theme into VerifyEmailMail', function (): void {
    $user = User::factory()->unverified()->createOne();
    $mail = new VerifyEmailMail(
        user: $user,
        verifyUrl: 'https://example.test/verify?token=x',
        expiresInHours: 24,
    );

    $html = strtolower((string) $mail->render());

    expect($html)->toContain(BRAND_TEAL_700);
    expect($html)->not->toContain(STOCK_DEFAULT_BUTTON);
});

it('inlines the brand teal mail theme into ResetPasswordMail', function (): void {
    $user = User::factory()->createOne();
    $mail = new ResetPasswordMail(
        user: $user,
        resetUrl: 'https://example.test/reset?token=x',
        expiresInMinutes: 60,
    );

    $html = strtolower((string) $mail->render());

    expect($html)->toContain(BRAND_TEAL_700);
    expect($html)->not->toContain(STOCK_DEFAULT_BUTTON);
});

it('inlines the brand teal mail theme into InviteAgencyUserMail', function (): void {
    $agency = Agency::factory()->createOne();
    $inviter = User::factory()->createOne();
    $mail = new InviteAgencyUserMail(
        agency: $agency,
        inviter: $inviter,
        inviteeName: 'Jordan',
        role: AgencyRole::AgencyManager,
        acceptUrl: 'https://example.test/accept-invitation?token=x',
        expiresInDays: 7,
    );

    $html = strtolower((string) $mail->render());

    expect($html)->toContain(BRAND_TEAL_700);
    expect($html)->not->toContain(STOCK_DEFAULT_BUTTON);
});

it('inlines the brand teal mail theme into ProspectCreatorInviteMail', function (): void {
    $mail = new ProspectCreatorInviteMail(
        agencyName: 'Acme Agency',
        token: 'a-prospect-token',
        expiresAt: '2026-12-31',
    );

    $html = strtolower((string) $mail->render());

    expect($html)->toContain(BRAND_TEAL_700);
    expect($html)->not->toContain(STOCK_DEFAULT_BUTTON);
});
