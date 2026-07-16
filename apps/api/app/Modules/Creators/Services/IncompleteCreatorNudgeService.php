<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Modules\Creators\CreatorsServiceProvider;
use App\Modules\Creators\Enums\IncompleteCreatorNudgeVariant;
use App\Modules\Creators\Features\IncompleteCreatorNudgeEnabled;
use App\Modules\Creators\Mail\IncompleteCreatorNudgeMail;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Support\IncompleteNudgeReport;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\EmailVerificationToken;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

/**
 * Sends the one-time incomplete-creator nudge (D4/D6). One command, two
 * variants, one stamp. The Pennant flag is checked HERE (not in the command)
 * so the console send and the admin toggle agree via the null-scope pin
 * ({@see CreatorsServiceProvider::configurePennantScope()}).
 *
 * - {@see self::send()}    — flag-gated. OFF → an explicit no-op (nothing queued,
 *                            nothing stamped; the break-revert anchor). ON →
 *                            one email per eligible creator + the once-only stamp.
 * - {@see self::preview()} — flag-agnostic, mutation-free: the --dry-run counts an
 *                            operator reads BEFORE flipping the flag.
 *
 * Idempotent: {@see IncompleteCreatorNudgeEligibility} excludes anyone already
 * carrying `incomplete_nudge_sent_at`, so a second run sends zero.
 */
final class IncompleteCreatorNudgeService
{
    /**
     * Conservative per-run cap (production-safety addendum). We are LIVE, so a
     * daily run drains at most this many nudges — oldest-first — bounding blast
     * radius on the first enable and smoothing any backlog over successive days.
     * Overridable via `--limit=N`; the operator raises it once a dry-run shows
     * the backlog is safe to drain faster.
     */
    public const DEFAULT_LIMIT = 50;

    /**
     * SPA route for the finish-profile variant (D4). No step encoding — the
     * onboarding guard + next_step resumption does the routing; no magic-login.
     */
    private const FINISH_PATH = '/onboarding';

    /**
     * SPA route for the verify-email variant (D4/Q2). Kept in lockstep with
     * {@see \App\Modules\Identity\Services\SignUpService::buildVerifyUrl()};
     * the §5.3 render test asserts the full shape appears in the rendered body,
     * so any drift is a red test rather than a silent 404. A THIRD mint site
     * appearing is the extraction trigger (see the review file, Q2).
     */
    private const VERIFY_PATH = '/auth/verify-email';

    public function __construct(
        private readonly IncompleteCreatorNudgeEligibility $eligibility,
        private readonly EmailVerificationToken $tokens,
        private readonly Repository $config,
    ) {}

    /**
     * Flag-agnostic, mutation-free preview of the would-send populations for a
     * run capped at `$limit` (oldest-first) — exactly what a real send at the
     * same limit would queue, so the dry-run counts an operator reads match the
     * subsequent send.
     */
    public function preview(int $limit = self::DEFAULT_LIMIT): IncompleteNudgeReport
    {
        $eligible = $this->eligibility->eligible($limit);

        $verify = $eligible->filter(
            static fn (Creator $creator): bool => $creator->user instanceof User && $creator->user->email_verified_at === null,
        )->count();

        return new IncompleteNudgeReport(
            verify: $verify,
            finish: $eligible->count() - $verify,
        );
    }

    /**
     * Flag-gated send, capped at `$limit` (oldest-first). OFF → no-op (the
     * break-revert anchor); ON → queue one localized email per eligible creator
     * in the capped set and stamp `incomplete_nudge_sent_at`. Only the capped
     * set is stamped — a run at the cap never over-stamps the tail (§5.34).
     */
    public function send(int $limit = self::DEFAULT_LIMIT): IncompleteNudgeReport
    {
        if (! Feature::active(IncompleteCreatorNudgeEnabled::NAME)) {
            return IncompleteNudgeReport::disabled();
        }

        $verify = 0;
        $finish = 0;

        foreach ($this->eligibility->eligible($limit) as $creator) {
            $user = $creator->user;
            if (! $user instanceof User) {
                continue; // unreachable: whereHas('user') guarantees a row.
            }

            if ($user->email_verified_at === null) {
                $this->dispatchVerify($user);
                $verify++;
            } else {
                $this->dispatchFinish($user);
                $finish++;
            }

            $this->stamp($creator);
        }

        return new IncompleteNudgeReport(verify: $verify, finish: $finish);
    }

    private function dispatchVerify(User $user): void
    {
        Mail::to($user->email)
            ->locale($user->preferred_language ?: 'en')
            ->queue(new IncompleteCreatorNudgeMail(
                user: $user,
                variant: IncompleteCreatorNudgeVariant::Verify,
                actionUrl: $this->buildVerifyUrl($this->tokens->mint($user)),
                expiresInHours: EmailVerificationToken::LIFETIME_HOURS,
            ));
    }

    private function dispatchFinish(User $user): void
    {
        Mail::to($user->email)
            ->locale($user->preferred_language ?: 'en')
            ->queue(new IncompleteCreatorNudgeMail(
                user: $user,
                variant: IncompleteCreatorNudgeVariant::Finish,
                actionUrl: $this->buildFinishUrl(),
            ));
    }

    /**
     * The send-stamp IS the send record (no per-send audit row — D6/§5.4 N/A).
     * updateQuietly bypasses the Audited observer: `incomplete_nudge_sent_at` is
     * not an auditable attribute and the nudge must not write an audit row.
     */
    private function stamp(Creator $creator): void
    {
        $creator->updateQuietly(['incomplete_nudge_sent_at' => now()]);
    }

    /**
     * Replicates {@see SignUpService::buildVerifyUrl()} verbatim (Q2). The §5.3
     * render test pins the full shape in the rendered body.
     */
    private function buildVerifyUrl(string $token): string
    {
        return $this->frontendBase().self::VERIFY_PATH.'?'.http_build_query(['token' => $token]);
    }

    private function buildFinishUrl(): string
    {
        return $this->frontendBase().self::FINISH_PATH;
    }

    private function frontendBase(): string
    {
        return rtrim((string) $this->config->get('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');
    }
}
