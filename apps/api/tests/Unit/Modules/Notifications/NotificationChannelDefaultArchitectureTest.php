<?php

declare(strict_types=1);

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use Tests\TestCase;

uses(TestCase::class);

/**
 * AH-102 (C1.1) — "every preference default goes through the type-aware resolver".
 *
 * The default for a (type, channel) pair is {@see NotificationChannel::defaultEnabledFor()}.
 * The bare channel baseline {@see NotificationChannel::defaultEnabled()} is an
 * implementation detail of that enum: a call site that reads it directly would
 * silently ignore a per-type override. This is the structural miss the scan
 * prevents — the read, the sparse write and the `defaults` contract all agreeing
 * on one answer is what keeps an opt-in from being "deleted back to OFF"
 * (the `setPreference` failure mode described in the AH-102 plan, §4.1).
 *
 * Comments are stripped first so a docblock mentioning the method is not a call.
 */

/**
 * @return array<string, string> repo-relative path => comment-free source, for every file under `app/`
 */
function channelDefaultSources(): array
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

        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }
            $code .= $token;
        }

        $files['app/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/')] = $code;
    }

    ksort($files);

    return $files;
}

it('no code outside the NotificationChannel enum calls the bare defaultEnabled() baseline', function (): void {
    $offenders = [];

    foreach (channelDefaultSources() as $path => $code) {
        if ($path === 'app/Modules/Notifications/Enums/NotificationChannel.php') {
            continue;
        }

        if (preg_match('/->\s*defaultEnabled\s*\(/', $code) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Use NotificationChannel::defaultEnabledFor($type) (or ::baselineDefaults() for the contract block): '.implode(', ', $offenders));
});

it('the scan itself would catch a direct call (the scanner is not vacuous)', function (): void {
    $sources = channelDefaultSources();

    // The enum itself must be visible to the scan, and must contain the call the
    // rule forbids elsewhere — proof the regex matches real source.
    expect($sources)->toHaveKey('app/Modules/Notifications/Enums/NotificationChannel.php')
        ->and(preg_match('/->\s*defaultEnabled\s*\(/', $sources['app/Modules/Notifications/Enums/NotificationChannel.php']))->toBe(1);
});

it('the per-type override map is exactly what has been ruled (no existing default can move silently)', function (): void {
    // The ONE ruled exception (AH-102 Q3): promotional push is opt-in. Nothing
    // else — in particular no in_app / email / digest default — may ever appear
    // here without this test, and the stored-state reasoning in
    // NotificationService::setPreference()'s docblock, being revisited.
    expect(NotificationChannel::typeDefaultOverrides())->toBe([
        'campaign.job_posted' => ['push' => false],
    ]);

    foreach (NotificationType::cases() as $type) {
        foreach (NotificationChannel::cases() as $channel) {
            $overridden = $type === NotificationType::CampaignJobPosted && $channel === NotificationChannel::Push;

            expect($channel->defaultEnabledFor($type))->toBe($overridden ? false : $channel->defaultEnabled());
        }
    }
});
