<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Events\EmailVerified;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Verification + resend half of the email-verification flow.
 *
 *   - {@see verify()} decodes the {@see EmailVerificationToken}, checks
 *     signature, expiry, that the embedded `email_hash` still matches
 *     the user's current email (defends against replay after an email
 *     change), and finally marks the user verified. Re-clicks on a
 *     verified user short-circuit with {@see EmailVerificationResult::AlreadyVerified}
 *     — that is the single-use guarantee.
 *
 *   - {@see resend()} re-mints + re-queues the verification mail when
 *     the user is known and not yet verified. Returns silently in every
 *     other case (unknown email, already verified) — same
 *     user-enumeration defence the password-reset flow uses.
 */
final class EmailVerificationService
{
    public function __construct(
        private readonly EmailVerificationToken $tokens,
        private readonly Dispatcher $events,
        private readonly SignUpService $signUp,
    ) {}

    public function verify(string $token, Request $request): EmailVerificationResult
    {
        $payload = $this->tokens->decode($token);

        if (! $payload->valid) {
            return EmailVerificationResult::InvalidToken;
        }

        if ($payload->isExpired()) {
            return EmailVerificationResult::ExpiredToken;
        }

        $user = User::query()->find($payload->userId);

        if (! $user instanceof User) {
            return EmailVerificationResult::InvalidToken;
        }

        if (! hash_equals($payload->emailHash, $this->tokens->hashEmail($user->email))) {
            return EmailVerificationResult::InvalidToken;
        }

        if ($user->email_verified_at !== null) {
            return EmailVerificationResult::AlreadyVerified;
        }

        DB::transaction(static function () use ($user): void {
            $user->forceFill(['email_verified_at' => now()])->save();
        });

        $this->events->dispatch(new EmailVerified(
            user: $user,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        return EmailVerificationResult::Verified;
    }

    public function resend(string $email, Request $request): void
    {
        $normalised = strtolower(trim($email));
        $user = User::query()->where('email', $normalised)->first();

        if (! $user instanceof User) {
            return;
        }

        if ($user->email_verified_at !== null) {
            return;
        }

        $this->signUp->sendVerificationMail($user, $request);
    }
}
