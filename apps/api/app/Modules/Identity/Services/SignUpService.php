<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Creators\Enums\RelationshipStatus;
use App\Modules\Creators\Services\CreatorBootstrapService;
use App\Modules\Identity\Enums\ThemePreference;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Events\EmailVerificationSent;
use App\Modules\Identity\Events\UserSignedUp;
use App\Modules\Identity\Exceptions\InvitationAcceptException;
use App\Modules\Identity\Listeners\WriteAuthAuditLog;
use App\Modules\Identity\Mail\VerifyEmailMail;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Rules\PasswordIsNotBreached;
use App\Modules\Identity\Rules\StrongPassword;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Self-serve sign-up. Phase 1 only ever creates a creator-typed user;
 * agency invitations and admin provisioning live in their own services.
 *
 * Strict invariants:
 *
 *   - Creates the User row; delegates Creator-row bootstrap to
 *     {@see CreatorBootstrapService} (see
 *     app/Modules/Creators/Services/CreatorBootstrapService.php). Single
 *     transaction guarantees no User-without-Creator state. No other
 *     satellite profile rows (admin_profiles, agency_users) are touched
 *     here — those are owned by their respective modules.
 *
 *   - NO authentication side effects: no session cookie, no
 *     `Auth::login()`, no `last_login_*` stamping. The user must
 *     verify their email and then sign in separately
 *     (docs/05-SECURITY-COMPLIANCE.md §6.5).
 *
 *   - Password is hashed via the configured driver (Argon2id by default,
 *     see `config/hashing.php`). The HIBP and length rules are enforced
 *     at the Form Request layer via {@see StrongPassword}
 *     and {@see PasswordIsNotBreached} —
 *     the same rules used by password reset.
 *
 *   - Email is normalised (lower-cased, trimmed) before insert so the
 *     unique index on `users.email` actually catches case-variant
 *     duplicates the Form Request let through.
 *
 *   - Two events fire in order: {@see UserSignedUp} then
 *     {@see EmailVerificationSent}. Audit rows are written by
 *     {@see WriteAuthAuditLog}.
 */
final class SignUpService
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly MailFactory $mail,
        private readonly Repository $config,
        private readonly EmailVerificationToken $tokens,
        private readonly CreatorBootstrapService $creatorBootstrap,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated payload
     */
    public function register(array $attributes, Request $request): User
    {
        $email = strtolower(trim((string) $attributes['email']));
        $name = trim((string) $attributes['name']);
        $password = (string) $attributes['password'];
        $preferredLanguage = $this->normaliseLanguage($attributes['preferred_language'] ?? null);
        $invitationToken = isset($attributes['invitation_token'])
            && is_string($attributes['invitation_token'])
            && trim($attributes['invitation_token']) !== ''
                ? trim($attributes['invitation_token'])
                : null;

        // Sprint 3 Chunk 4 — magic-link invitation path. The BulkInviteService
        // pre-created the User row at invite time; sign-up completes it
        // rather than creating a fresh one. The hard-lock from Decision C2=a
        // is enforced here as a post-submit gate: typed email must match the
        // invited User's email (case-insensitive) or we throw email_mismatch.
        if ($invitationToken !== null) {
            return $this->acceptInvitationOnSignUp(
                token: $invitationToken,
                email: $email,
                name: $name,
                password: $password,
                preferredLanguage: $preferredLanguage,
                request: $request,
            );
        }

        /** @var User $user */
        $user = DB::transaction(function () use ($email, $name, $password, $preferredLanguage): User {
            $user = User::query()->create([
                'email' => $email,
                'name' => $name,
                'password' => $password,
                'type' => UserType::Creator,
                'preferred_language' => $preferredLanguage,
                'preferred_currency' => $this->config->get('app.default_currency', 'EUR'),
                'timezone' => $this->config->get('app.timezone', 'UTC'),
                'theme_preference' => ThemePreference::System,
                'mfa_required' => false,
                'is_suspended' => false,
                'email_verified_at' => null,
            ]);

            // Bootstrap the Creator satellite row in the same transaction.
            // Failure to create the Creator row aborts the User insert too,
            // so the database never holds a creator-typed User without
            // a corresponding Creator row.
            $this->creatorBootstrap->bootstrapForUser($user);

            return $user;
        });

        $this->events->dispatch(new UserSignedUp(
            user: $user,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        $this->sendVerificationMail($user, $request);

        return $user;
    }

    /**
     * Magic-link invitation acceptance path. Looks up the
     * AgencyCreatorRelation by token hash, validates the four failure
     * modes (not_found, expired, already_accepted, email_mismatch),
     * then updates the pre-created User row + transitions the relation
     * to roster status in a single transaction.
     *
     * Email verification: the invitee clicked a link mailed to them, so
     * email_verified_at is stamped to now() — they don't need to click
     * a second verification mail to enter the wizard.
     *
     * @throws InvitationAcceptException When the token is invalid for
     *                                   any of the four reasons.
     */
    public function acceptInvitationOnSignUp(
        string $token,
        string $email,
        string $name,
        string $password,
        string $preferredLanguage,
        Request $request,
    ): User {
        $tokenHash = hash('sha256', $token);

        $relation = AgencyCreatorRelation::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('invitation_token_hash', $tokenHash)
            ->with(['creator.user'])
            ->first();

        if ($relation === null) {
            throw new InvitationAcceptException(
                errorCode: 'invitation.not_found',
                message: 'Invitation token not found.',
            );
        }

        if ($relation->isInvitationExpired()) {
            throw new InvitationAcceptException(
                errorCode: 'invitation.expired',
                message: 'Invitation has expired.',
            );
        }

        if ($relation->relationship_status !== RelationshipStatus::Prospect) {
            throw new InvitationAcceptException(
                errorCode: 'invitation.already_accepted',
                message: 'Invitation has already been accepted.',
            );
        }

        $invitedUser = $relation->creator?->user;
        if ($invitedUser === null) {
            // Defensive — bulk-invite always wires both User + Creator,
            // so we should never hit this. Treat as not_found rather than
            // 500 to avoid leaking the partial state.
            throw new InvitationAcceptException(
                errorCode: 'invitation.not_found',
                message: 'Invitation user is missing.',
            );
        }

        if (mb_strtolower(trim($invitedUser->email)) !== $email) {
            throw new InvitationAcceptException(
                errorCode: 'invitation.email_mismatch',
                message: 'Email does not match the invited user.',
            );
        }

        /** @var User $user */
        $user = DB::transaction(function () use ($relation, $invitedUser, $name, $password, $preferredLanguage): User {
            // The hashing cast on User normally runs on attribute mutation,
            // but we forceFill() here to update the placeholder password
            // from bulk-invite without bypassing the hash. Setting the
            // attribute through the standard setter ensures the hashed
            // cast fires.
            $invitedUser->name = $name;
            $invitedUser->password = $password;
            $invitedUser->preferred_language = $preferredLanguage;
            $invitedUser->email_verified_at = now();
            $invitedUser->save();

            $relation->relationship_status = RelationshipStatus::Roster->value;
            // Defense-in-depth: clear the token hash so the magic-link
            // can never be replayed.
            $relation->invitation_token_hash = null;
            $relation->invitation_expires_at = null;
            $relation->save();

            Audit::log(
                action: AuditAction::CreatorInvitationAccepted,
                actor: $invitedUser,
                subject: $relation,
                agencyId: $relation->agency_id,
            );

            return $invitedUser;
        });

        $this->events->dispatch(new UserSignedUp(
            user: $user,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return $user;
    }

    public function sendVerificationMail(User $user, Request $request): void
    {
        $token = $this->tokens->mint($user);

        $this->mail
            ->mailer()
            ->to($user->email)
            ->locale($user->preferred_language ?: 'en')
            ->queue(new VerifyEmailMail(
                user: $user,
                verifyUrl: $this->buildVerifyUrl($token),
                expiresInHours: EmailVerificationToken::LIFETIME_HOURS,
            ));

        $this->events->dispatch(new EmailVerificationSent(
            user: $user,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));
    }

    private function normaliseLanguage(mixed $candidate): string
    {
        $allowed = ['en', 'pt', 'it'];
        $value = is_string($candidate) ? strtolower(trim($candidate)) : '';

        return in_array($value, $allowed, true) ? $value : 'en';
    }

    private function buildVerifyUrl(string $token): string
    {
        $base = rtrim((string) $this->config->get('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');

        return $base.'/auth/verify-email?'.http_build_query(['token' => $token]);
    }
}
