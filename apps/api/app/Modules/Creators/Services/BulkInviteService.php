<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Mail\ProspectCreatorInviteMail;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-row processor for bulk creator invitations.
 *
 * For each invitee email, the service:
 *
 *   1. Pre-creates a User row (no password yet, email_verified_at null).
 *      If a User already exists for the email, it's reused.
 *   2. Pre-creates a Creator row (bootstrap state) via
 *      {@see CreatorBootstrapService}. Reused if already present.
 *   3. Creates an AgencyCreatorRelation in `prospect` status with the
 *      magic-link token hash, expiry (now + 7 days), and inviter id.
 *   4. Queues the {@see ProspectCreatorInviteMail} with the unhashed
 *      token. The unhashed token is never persisted (Q1 hardening).
 *
 * If a relation already exists for (agency, creator) the row is treated
 * as a no-op (idempotent re-invite) — the previous invitation token
 * is left alone and a fresh one is NOT issued. This avoids token
 * confusion and matches Sprint 2's invitation-not-recreated behaviour.
 *
 * The processor returns a per-row outcome so the caller (the queued
 * job) can aggregate stats into the TrackedJob.result column.
 */
final class BulkInviteService
{
    public const int INVITATION_LIFETIME_DAYS = 7;

    public function __construct(
        private readonly CreatorBootstrapService $creatorBootstrap,
        private readonly MailFactory $mail,
    ) {}

    /**
     * @return array{outcome: 'invited'|'already_invited'|'failed', email: string, reason?: string}
     */
    public function inviteOne(Agency $agency, User $inviter, string $email): array
    {
        $email = mb_strtolower(trim($email));

        try {
            $result = DB::transaction(function () use ($agency, $inviter, $email): array {
                $user = $this->findOrCreateInviteeUser($email);
                $creator = $this->findOrCreateInviteeCreator($user);

                $existing = AgencyCreatorRelation::query()
                    ->where('agency_id', $agency->id)
                    ->where('creator_id', $creator->id)
                    ->first();

                if ($existing !== null) {
                    return ['outcome' => 'already_invited', 'email' => $email];
                }

                $token = (string) Str::ulid().bin2hex(random_bytes(16));
                $hash = hash('sha256', $token);

                $relation = AgencyCreatorRelation::create([
                    'agency_id' => $agency->id,
                    'creator_id' => $creator->id,
                    'relationship_status' => RelationshipStatus::Prospect->value,
                    'invitation_token_hash' => $hash,
                    'invitation_expires_at' => Carbon::now()->addDays(self::INVITATION_LIFETIME_DAYS),
                    'invitation_sent_at' => Carbon::now(),
                    'invited_by_user_id' => $inviter->id,
                ]);

                Audit::log(
                    action: AuditAction::CreatorInvited,
                    actor: $inviter,
                    subject: $relation,
                    agencyId: $agency->id,
                );

                $this->mail
                    ->mailer()
                    ->to($email)
                    ->locale($user->preferred_language ?: 'en')
                    ->queue(new ProspectCreatorInviteMail(
                        agencyName: $agency->name,
                        token: $token,
                        expiresAt: $relation->invitation_expires_at?->toIso8601String() ?? '',
                    ));

                return ['outcome' => 'invited', 'email' => $email];
            });
        } catch (\Throwable $e) {
            return [
                'outcome' => 'failed',
                'email' => $email,
                'reason' => $e->getMessage(),
            ];
        }

        return $result;
    }

    private function findOrCreateInviteeUser(string $email): User
    {
        $user = User::query()->where('email', $email)->first();
        if ($user !== null) {
            return $user;
        }

        return User::query()->create([
            'email' => $email,
            'name' => $email,
            // Random un-usable password — the invitee sets a real one
            // at acceptance via the dedicated /accept-invite flow.
            'password' => bin2hex(random_bytes(32)),
            'type' => UserType::Creator,
            'preferred_language' => 'en',
            'preferred_currency' => 'EUR',
            'timezone' => 'UTC',
            'mfa_required' => false,
            'is_suspended' => false,
            'email_verified_at' => null,
        ]);
    }

    private function findOrCreateInviteeCreator(User $user): Creator
    {
        $creator = Creator::query()->where('user_id', $user->id)->first();
        if ($creator !== null) {
            return $creator;
        }

        return $this->creatorBootstrap->bootstrapForUser($user);
    }
}
