<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Mail\InviteAgencyUserMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * Render the InviteAgencyUserMail in a given locale.
 *
 * @return array{subject: string, body: string}
 */
function renderInviteMail(InviteAgencyUserMail $mail, string $locale): array
{
    $previous = App::getLocale();
    App::setLocale($locale);

    try {
        return [
            'subject' => $mail->envelope()->subject ?? '',
            'body' => $mail->render(),
        ];
    } finally {
        App::setLocale($previous);
    }
}

// ---------------------------------------------------------------------------
// Real-rendering tests per Sprint 1 mailable standard (chunk 4).
// ---------------------------------------------------------------------------

it('renders the invitation mail in English', function (): void {
    $agency = Agency::factory()->createOne(['name' => 'Acme Agency']);
    $inviter = User::factory()->createOne(['name' => 'Alice Admin']);

    $mail = new InviteAgencyUserMail(
        agency: $agency,
        inviter: $inviter,
        inviteeName: 'Bob Invitee',
        role: AgencyRole::AgencyManager,
        acceptUrl: 'https://app.example.com/accept-invitation?token=abc123',
        expiresInDays: 7,
    );

    $result = renderInviteMail($mail, 'en');

    expect($result['subject'])
        ->toContain('Acme Agency')
        ->toContain(config('app.name'));

    expect($result['body'])
        ->toContain('Acme Agency')
        ->toContain('Alice Admin')
        ->toContain('accept-invitation')
        ->toContain('7'); // expiry days
});

it('renders the invitation mail in Portuguese', function (): void {
    $agency = Agency::factory()->createOne(['name' => 'Acme Agency']);
    $inviter = User::factory()->createOne(['name' => 'Alice Admin']);

    $mail = new InviteAgencyUserMail(
        agency: $agency,
        inviter: $inviter,
        inviteeName: 'Bob Convidado',
        role: AgencyRole::AgencyStaff,
        acceptUrl: 'https://app.example.com/accept-invitation?token=abc123',
        expiresInDays: 7,
    );

    $result = renderInviteMail($mail, 'pt');

    expect($result['subject'])->toContain('Acme Agency');
    expect($result['body'])->toContain('Aceitar Convite');
});

it('renders the invitation mail in Italian', function (): void {
    $agency = Agency::factory()->createOne(['name' => 'Acme Agency']);
    $inviter = User::factory()->createOne(['name' => 'Alice Admin']);

    $mail = new InviteAgencyUserMail(
        agency: $agency,
        inviter: $inviter,
        inviteeName: 'Bob Invitato',
        role: AgencyRole::AgencyAdmin,
        acceptUrl: 'https://app.example.com/accept-invitation?token=abc123',
        expiresInDays: 7,
    );

    $result = renderInviteMail($mail, 'it');

    expect($result['subject'])->toContain('Acme Agency');
    expect($result['body'])->toContain('Accetta Invito');
});

it('is tagged as agencies + invitation for ESP filtering', function (): void {
    $agency = Agency::factory()->createOne();
    $inviter = User::factory()->createOne();

    $mail = new InviteAgencyUserMail(
        agency: $agency,
        inviter: $inviter,
        inviteeName: 'Test',
        role: AgencyRole::AgencyStaff,
        acceptUrl: 'https://example.com/accept',
        expiresInDays: 7,
    );

    expect($mail->envelope()->tags)->toBe(['agencies', 'invitation']);
});
