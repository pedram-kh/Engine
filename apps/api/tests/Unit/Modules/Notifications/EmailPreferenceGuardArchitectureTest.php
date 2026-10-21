<?php

declare(strict_types=1);

use App\Modules\Notifications\Services\NotificationService;
use Tests\TestCase;

uses(TestCase::class);

/**
 * AH-101 (A1) — the executable form of "every email goes through the one guard".
 *
 * {@see NotificationService::isEmailEnabled()} is the single email preference
 * check. A new mail site that forgets it silently ignores a user's opt-out, and
 * nothing at runtime would ever say so. This walks every `.php` file under
 * `app/`, strips comments, and asserts that any file which SENDS mail either:
 *
 *   - calls `isEmailEnabled(` (it is a guarded site), or
 *   - is on the allowlist below, WITH A REASON.
 *
 * It is file-level by design — the per-site both-polarity behaviour lives in
 * EmailPreferenceGuardTest. This test only prevents the structural miss: a whole
 * new sending file nobody classified.
 *
 * To add an unguarded mail on purpose, add the file to the allowlist and write the
 * reason. "Always on" and "no NotificationType" are valid reasons; "I forgot" is
 * not. A mail that HAS a user-facing type and is not always-on belongs behind the
 * guard (and in the preferences matrix) instead.
 */

/**
 * Files that send mail without the preference guard, and why.
 *
 * @return array<string, string> repo-relative path => reason
 */
function emailGuardAllowlist(): array
{
    return [
        // ── No NotificationType: not a notification, so no preference exists ──
        'app/Console/Commands/SendMessageDigests.php' => 'Digest — its own `digest` channel, opt-in (default OFF), selected upstream by that channel.',
        'app/Modules/Identity/Services/SignUpService.php' => 'Transactional: email verification at sign-up. No type; the recipient has no account yet.',
        'app/Modules/Identity/Services/PasswordResetService.php' => 'Transactional: password reset. Security-critical, must always arrive.',
        'app/Modules/Identity/Listeners/SendAccountDeletionRequestedMail.php' => 'Account-lifecycle security notice (AH-104b-del): the confirmation that deletion was requested, with the date and what to do if it was not them. Must always arrive; no NotificationType, so no preference exists.',
        'app/Modules/Agencies/Services/AgencyInvitationService.php' => 'Invites a person who may have no account (and so no preferences) to join an agency.',
        'app/Modules/Admin/Http/Controllers/AdminAgencyMemberController.php' => 'Admin-initiated agency-member invite/reset. Account lifecycle, no type.',
        'app/Modules/Creators/Services/BulkInviteService.php' => 'Admin bulk invite of people who may have no account. No type.',
        'app/Modules/Creators/Services/IncompleteCreatorNudgeService.php' => 'Onboarding nudge with its own kill-switch flag and its own once-only ledger; no NotificationType. A preference surface for it is a separate decision.',
        'app/Modules/Agencies/Http/Controllers/CreatorBlacklistController.php' => 'Account-level blacklist notice. No type.',
        'app/Modules/Agencies/Http/Controllers/AgencyConnectionRequestController.php' => 'Connection-request mail. No type; relation lifecycle, always on.',
        // ── Always-on by ruling (preference: null in the FE registry) ──
        'app/Modules/Creators/Http/Controllers/Admin/AdminCreatorController.php' => 'creator.approved / creator.rejected — account-lifecycle transactional, always on (AH-101 Q2).',
        'app/Modules/Creators/Http/Controllers/Admin/AdminCreatorConnectionController.php' => 'agency_creator_relation.* — admin-initiated relation events, always on (`preference: null`).',
        'app/Modules/Creators/Services/CreatorAgencyDirectConnectService.php' => 'agency_creator_relation.admin_connected — always on (`preference: null`).',
        // ── Mail that is not a notification of an event the recipient can mute ──
        'app/Modules/Campaigns/Jobs/VerifyPostedContentJob.php' => 'Verification-FAILED mail to the agency inviter. Operational alert about a failed automatic check; no NotificationType. Agency-side preferences are a named AH-101 follow-up.',
        'app/Modules/Campaigns/Http/Controllers/CampaignAssignmentContractController.php' => 'Contract mail carrying a signing link to the creator. Transactional; no NotificationType.',
        'app/Modules/Campaigns/Http/Controllers/CampaignAssignmentResolutionController.php' => 'Resolution (decline/counter/accept) mail to the agency inviter. No NotificationType; agency-side preferences are a named AH-101 follow-up.',
    ];
}

/**
 * Strip comments so a docblock that *mentions* `Mail::to(` is not a send site.
 */
function emailGuardCode(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

/**
 * @return array<string, string> repo-relative path => comment-free source, for every file under `app/`
 */
function emailGuardSources(): array
{
    $root = base_path('app');
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = 'app/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
        $files[$relative] = emailGuardCode((string) file_get_contents($file->getPathname()));
    }

    ksort($files);

    return $files;
}

function emailGuardSends(string $code): bool
{
    return preg_match('/Mail::(to|queue|send|later|mailer|raw)\s*\(|->mailer\s*\(/', $code) === 1;
}

it('every file that sends mail is guarded or on the named allowlist', function (): void {
    $allow = emailGuardAllowlist();
    $unclassified = [];

    foreach (emailGuardSources() as $path => $code) {
        if (! emailGuardSends($code)) {
            continue;
        }

        if (str_contains($code, 'isEmailEnabled(') || array_key_exists($path, $allow)) {
            continue;
        }

        $unclassified[] = $path;
    }

    expect($unclassified)->toBe([], 'These files send mail without isEmailEnabled() and are not on the allowlist. Guard them (NotificationService::isEmailEnabled) or allowlist them with a reason: '.implode(', ', $unclassified));
});

it('the allowlist has no stale entries — each file exists and still sends mail', function (): void {
    $sources = emailGuardSources();

    foreach (emailGuardAllowlist() as $path => $reason) {
        expect(array_key_exists($path, $sources))->toBeTrue("Allowlisted file {$path} no longer exists — remove the entry.");
        expect(emailGuardSends($sources[$path]))->toBeTrue("Allowlisted file {$path} no longer sends mail — remove the entry.");
        expect(trim($reason))->not->toBe('');
    }
});

it('no allowlisted file also calls the guard (it would belong in the guarded set)', function (): void {
    $sources = emailGuardSources();

    foreach (array_keys(emailGuardAllowlist()) as $path) {
        expect(str_contains($sources[$path], 'isEmailEnabled('))->toBeFalse("{$path} calls isEmailEnabled() — drop it from the allowlist.");
    }
});

it('the guarded sites are exactly the files the AH-101 guard test drives', function (): void {
    $guarded = [];

    foreach (emailGuardSources() as $path => $code) {
        if (emailGuardSends($code) && str_contains($code, 'isEmailEnabled(')) {
            $guarded[] = $path;
        }
    }

    expect($guarded)->toBe([
        'app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php',
        'app/Modules/Campaigns/Services/CampaignApplicationNotifier.php',
        'app/Modules/Campaigns/Services/JobPostedFanOutService.php',
        'app/Modules/Messaging/Services/DebouncedMessageMailer.php',
    ], 'A new guarded file needs a row in EmailPreferenceGuardTest::guardSites().');
});
