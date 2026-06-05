<?php

declare(strict_types=1);

namespace App\Modules\Creators\Integrations\Contracts;

use App\Modules\Creators\Integrations\DataTransferObjects\PostVerification;
use App\Modules\Creators\Integrations\Stubs\DeferredSocialProvider;
use App\Modules\Creators\Models\CreatorSocialAccount;

/**
 * Social-platform provider contract (Sprint 9 Chunk 2, D-9).
 *
 * ## Sprint 9 Chunk 2 completion surface (this contract — 1 method)
 *   - {@see self::verifyPostUrl()}: confirm a submitted post URL belongs to
 *     the creator's connected account.
 *
 * The full eventual surface (OAuth authorize / token exchange + refresh,
 * account profile + metrics, media listing, per-post metrics, revoke) lives in
 * docs/06-INTEGRATIONS.md § 5.2 as `SocialPlatformProviderContract`. Per the
 * codebase precedent (KYC + e-sign shipped their wizard-critical subsets, not
 * the full vendor surface — see {@see EsignProvider}, and the
 * IntegrationProviderBindingsTest "exactly its built surface" tripwire), this
 * sprint ships ONLY `verifyPostUrl()` — the single load-bearing method for the
 * assignment lifecycle's verification step. The remaining OAuth/profile/metrics
 * surface lands with the real Meta/TikTok/YouTube adapters (logged in
 * docs/tech-debt.md).
 *
 * ## D-9 — keyed on the connected handle, not an OAuth access token
 * The documented contract signature is `verifyPostUrl(string $accessToken,
 * string $postUrl)`. The Phase-1 onboarding stub records the creator's social
 * `handle` (on {@see CreatorSocialAccount}) WITHOUT
 * a real OAuth token, so the verification matches on the connected handle + the
 * submitted URL. The real adapters will resolve the handle's stored token
 * internally — the call site (the verification job) passes the handle either
 * way, so this signature is forward-compatible.
 *
 * @see DeferredSocialProvider
 * @see PostVerification
 */
interface SocialPlatformProvider
{
    /**
     * Verify that `$postUrl` is a real post published by the account behind
     * `$handle`. Returns a {@see PostVerification} carrying the outcome
     * (verified / mismatch / not_found) + the resolved platform post id on a
     * verified match.
     */
    public function verifyPostUrl(string $handle, string $postUrl): PostVerification;
}
