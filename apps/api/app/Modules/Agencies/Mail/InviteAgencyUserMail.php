<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Mail;

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invitation email sent to a prospective agency member.
 *
 * The magic-link URL is the SPA's accept-invitation route
 * (`/accept-invitation?token={unhashed_token}`). The unhashed
 * token is ONLY present here (in the email) and in the
 * test-helper create response; it is never stored in the database
 * (only its SHA-256 hash is stored — see Q1 answer in review).
 *
 * Q2 answer: Option B — the magic-link goes to a dedicated accept
 * page that shows the invitation details before the user confirms.
 */
final class InviteAgencyUserMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Agency $agency,
        public readonly User $inviter,
        public readonly string $inviteeName,
        public readonly AgencyRole $role,
        public readonly string $acceptUrl,
        public readonly int $expiresInDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('invitations.email.subject', [
                'agency' => $this->agency->name,
                'app' => config('app.name'),
            ]),
            tags: ['agencies', 'invitation'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.agencies.invite-user',
            with: [
                'agencyName' => $this->agency->name,
                'inviterName' => $this->inviter->name,
                'inviteeName' => $this->inviteeName,
                'roleLabel' => trans('invitations.roles.'.$this->role->value),
                'acceptUrl' => $this->acceptUrl,
                'expiresInDays' => $this->expiresInDays,
                'appName' => config('app.name'),
            ],
        );
    }
}
