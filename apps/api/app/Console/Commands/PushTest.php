<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\UserPushToken;
use App\Modules\Notifications\Push\PushMessage;
use App\Modules\Notifications\Push\PushOutcome;
use App\Modules\Notifications\Push\PushPlatform;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Notifications\Push\PushSendResult;
use App\Modules\Notifications\Push\PushTarget;
use App\Modules\Notifications\Push\PushTokenRegistry;
use Illuminate\Console\Command;

/**
 * Operator furniture for push (AH-102, C2.5): the credential smoke test for the
 * deploy and the trigger for the device proofs.
 *
 *   push:test --validate-only
 *       No user, no device. Sends a provider-side VALIDATE-ONLY request for a
 *       deliberately fake token. It proves the credential decodes, the OAuth
 *       exchange works and the project id is accepted: the provider is expected
 *       to answer "that token is not a valid registration token" — which is only
 *       reachable AFTER authentication succeeded. Exit 0 only on that signal.
 *       (The exact provider answer to a fake token is NOT yet verified live —
 *       see the AH-102 review; a different answer exits non-zero with its code.)
 *
 *   push:test --user=<id> [--validate-only]
 *       The named user's LIVE tokens, one fixed neutral message. With
 *       --validate-only the provider checks the request and delivers nothing.
 *
 *   push:test --user=<id> --dry-run
 *       Prints what would be sent and to which token ids. Makes NO provider call
 *       and reads no credential.
 *
 * Deliberate limits (the data-command pattern, WORKING-PROCESS §9):
 *   - ONE named user, ONE fixed message, never fan-out — there is no --all, no
 *     --limit, no way to pass message text.
 *   - Read-only against our data: it never invalidates a token (it bypasses the
 *     job and recorder on purpose, so an operator probe cannot kill a real
 *     device's registration) and writes nothing.
 *   - NOT behind `push_notifications_enabled`: that flag gates product sends. This
 *     command is how the credential and the device are proven BEFORE the flag is
 *     armed; a real (non-validate) send reaches only the one user the operator
 *     named.
 *   - Idempotent in effect: re-running sends another test notification and
 *     changes no state.
 *   - Prints token IDS and outcomes, never token values.
 */
final class PushTest extends Command
{
    /** Obviously fake; never a real registration token. */
    private const PLACEHOLDER_TOKEN = 'push-test-placeholder-not-a-real-registration-token';

    protected $signature = 'push:test
        {--user= : The user id whose live device tokens receive the test (required unless --validate-only)}
        {--validate-only : Have the provider validate the request without delivering anything}
        {--dry-run : Print what would be sent; make no provider call}';

    protected $description = 'Send (or validate) ONE neutral test push to ONE named user, or smoke-test the push credential.';

    public function handle(PushSender $sender, PushTokenRegistry $tokens): int
    {
        $validateOnly = (bool) $this->option('validate-only');
        $dryRun = (bool) $this->option('dry-run');
        $userOption = $this->option('user');

        if ($userOption === null || $userOption === '') {
            if (! $validateOnly) {
                $this->error('--user=<id> is required (the only exception is --validate-only, the credential smoke test).');

                return self::FAILURE;
            }

            if ($dryRun) {
                $this->error('--dry-run needs --user=<id>: without a user there is nothing to preview.');

                return self::FAILURE;
            }

            return $this->credentialSmoke($sender);
        }

        $userId = $this->resolveUserId($userOption);
        if ($userId === null) {
            $this->error('--user must be a positive integer user id.');

            return self::FAILURE;
        }

        $user = User::query()->find($userId);
        if (! $user instanceof User) {
            $this->error("No user with id {$userId}.");

            return self::FAILURE;
        }

        $live = $tokens->liveTokensFor($user);
        if ($live->isEmpty()) {
            $this->error("User {$userId} has no live push tokens (none registered, or all invalidated / signed out).");

            return self::FAILURE;
        }

        $message = $this->message();

        if ($dryRun) {
            $this->info(sprintf(
                '[dry-run] would %s "%s" / "%s" to %d token(s) of user %d: %s. No provider call made.',
                $validateOnly ? 'VALIDATE' : 'SEND',
                $message->title,
                (string) $message->body,
                $live->count(),
                $userId,
                $live->map(static fn (UserPushToken $t): string => '#'.$t->id.' ('.$t->platform->value.')')->implode(', '),
            ));

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($live as $token) {
            $result = $sender->send($message, new PushTarget($token->platform, $token->token), $validateOnly);
            $this->line($this->describe($token, $result, $validateOnly));

            if (! $result->isDelivered()) {
                $failures++;
            }
        }

        if ($failures > 0) {
            $this->error("{$failures} of {$live->count()} token(s) did not succeed.");

            return self::FAILURE;
        }

        $this->info($validateOnly
            ? "Validated {$live->count()} token(s) for user {$userId}; nothing was delivered."
            : "Sent to {$live->count()} token(s) for user {$userId}.");

        return self::SUCCESS;
    }

    private function credentialSmoke(PushSender $sender): int
    {
        $result = $sender->send($this->message(), new PushTarget(PushPlatform::Android, self::PLACEHOLDER_TOKEN), validateOnly: true);

        // The provider only reaches "bad registration token" after it accepted our
        // credential, so that — and only that — proves the credential end to end.
        if ($result->outcome === PushOutcome::TokenInvalid || $result->isDelivered()) {
            $this->info('Credential OK: the provider accepted authentication and rejected only the placeholder token (as expected). Nothing was delivered.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Credential smoke test did NOT pass: outcome=%s code=%s%s',
            $result->outcome->value,
            $result->code ?? '-',
            $result->detail !== null ? ' detail='.$result->detail : '',
        ));

        return self::FAILURE;
    }

    private function message(): PushMessage
    {
        return new PushMessage(
            title: 'Catalyst',
            body: 'Test notification',
            data: ['type' => 'push.test', 'v' => 1],
        );
    }

    private function describe(UserPushToken $token, PushSendResult $result, bool $validateOnly): string
    {
        return sprintf(
            'token #%d (%s): %s%s%s',
            $token->id,
            $token->platform->value,
            $result->outcome->value,
            $result->code !== null ? ' code='.$result->code : '',
            $validateOnly ? ' [validate-only]' : '',
        );
    }

    private function resolveUserId(mixed $value): ?int
    {
        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }
}
