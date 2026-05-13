<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Services;

use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Mail\InviteAgencyUserMail;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Agencies\Models\AgencyUserInvitation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class AgencyInvitationService
{
    private const int EXPIRES_IN_DAYS = 7;

    /**
     * Create a new invitation and dispatch the magic-link email.
     *
     * User-enumeration defence: the method behaves identically whether
     * the email belongs to an existing user or not. The distinction is
     * resolved at accept time, not creation time.
     */
    public function invite(
        Agency $agency,
        User $inviter,
        string $email,
        AgencyRole $role,
    ): AgencyUserInvitation {
        // Generate a cryptographically-random unhashed token; store only
        // the hash. The unhashed token goes into the email exclusively.
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);

        $invitation = DB::transaction(function () use ($agency, $inviter, $email, $role, $tokenHash): AgencyUserInvitation {
            $invitation = AgencyUserInvitation::query()->create([
                'agency_id' => $agency->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => $tokenHash,
                'expires_at' => now()->addDays(self::EXPIRES_IN_DAYS),
                'invited_by_user_id' => $inviter->id,
            ]);

            Audit::log(
                action: AuditAction::InvitationCreated,
                actor: $inviter,
                subject: $invitation,
                after: ['email' => $email, 'role' => $role->value],
                agencyId: $agency->id,
            );

            return $invitation;
        });

        // Build the magic-link URL for the SPA accept page. The `agency`
        // parameter carries the agency ULID so the accept page can call
        // POST /api/v1/agencies/{agency}/invitations/accept — the endpoint
        // requires the agency identifier in the path.
        $acceptUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            .'/accept-invitation?token='.$token.'&agency='.$agency->ulid;

        // The invitee's name: if they already have an account, use it;
        // otherwise fall back to the email local part.
        $inviteeName = User::query()
            ->where('email', $email)
            ->value('name') ?? explode('@', $email)[0];

        Mail::to($email)->queue(new InviteAgencyUserMail(
            agency: $agency,
            inviter: $inviter,
            inviteeName: (string) $inviteeName,
            role: $role,
            acceptUrl: $acceptUrl,
            expiresInDays: self::EXPIRES_IN_DAYS,
        ));

        return $invitation;
    }

    /**
     * Accept an invitation by unhashed token.
     *
     * Q1 (single-use-with-retry-on-failure): multiple attempts before
     * `accepted_at` is set are fine. Once stamped → 409 from the controller.
     *
     * Q2 (Option B): the accepting user must already be authenticated.
     * The SPA's dedicated accept page handles sign-in / sign-up inline
     * before calling this endpoint.
     */
    public function accept(
        AgencyUserInvitation $invitation,
        User $acceptingUser,
    ): AgencyMembership {
        return DB::transaction(function () use ($invitation, $acceptingUser): AgencyMembership {
            // Stamp accepted_at + record who accepted.
            $invitation->update([
                'accepted_at' => now(),
                'accepted_by_user_id' => $acceptingUser->id,
            ]);

            // Create the agency membership.
            $membership = AgencyMembership::query()->create([
                'agency_id' => $invitation->agency_id,
                'user_id' => $acceptingUser->id,
                'role' => $invitation->role,
                'invited_by_user_id' => $invitation->invited_by_user_id,
                'invited_at' => $invitation->created_at,
                'accepted_at' => now(),
            ]);

            Audit::log(
                action: AuditAction::InvitationAccepted,
                actor: $acceptingUser,
                subject: $invitation,
                after: [
                    'role' => $invitation->role->value,
                    'agency_id' => $invitation->agency_id,
                ],
                agencyId: $invitation->agency_id,
            );

            return $membership;
        });
    }
}
