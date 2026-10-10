<?php

declare(strict_types=1);

use App\Console\Commands\PushTest;
use App\Modules\Notifications\Models\UserPushToken;
use App\Modules\Notifications\Push\PushMessage;
use App\Modules\Notifications\Push\PushPlatform;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Notifications\Push\PushSendResult;
use App\Modules\Notifications\Push\PushTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AH-102 C2.5 — `php artisan push:test`
|--------------------------------------------------------------------------
|
| One named user, one fixed neutral message, never fan-out; read-only against our
| data; never prints a token value. The provider is a scripted fake.
*/

/** Records what the command asked the provider to do and answers from a script. */
final class PtSender implements PushSender
{
    /** @var list<array{message: PushMessage, target: PushTarget, validateOnly: bool}> */
    public array $calls = [];

    /** @param  list<PushSendResult>  $script  consumed in order; the last repeats */
    public function __construct(private array $script) {}

    public function send(PushMessage $message, PushTarget $target, bool $validateOnly = false): PushSendResult
    {
        $this->calls[] = ['message' => $message, 'target' => $target, 'validateOnly' => $validateOnly];

        return count($this->script) > 1 ? array_shift($this->script) : $this->script[0];
    }
}

/** @param  list<PushSendResult>  $script */
function ptSender(array $script = []): PtSender
{
    $sender = new PtSender($script === [] ? [PushSendResult::delivered()] : $script);
    app()->instance(PushSender::class, $sender);

    return $sender;
}

/**
 * @param  array<string, mixed>  $params
 * @return array{0: int, 1: string}
 */
function ptRun(array $params): array
{
    $code = Artisan::call('push:test', $params);

    return [$code, Artisan::output()];
}

// ── Argument handling ────────────────────────────────────────────────────────

it('refuses to run without --user unless --validate-only (and makes no provider call)', function (): void {
    $sender = ptSender();

    [$code, $out] = ptRun([]);

    expect($code)->toBe(1)
        ->and($out)->toContain('--user=<id> is required')
        ->and($sender->calls)->toBe([]);
});

it('--dry-run without a user is refused (nothing to preview)', function (): void {
    $sender = ptSender();

    [$code] = ptRun(['--dry-run' => true, '--validate-only' => true]);

    expect($code)->toBe(1)
        ->and($sender->calls)->toBe([]);
});

it('fails loudly on a non-numeric, zero or unknown user id', function (string $value): void {
    $sender = ptSender();

    [$code, $out] = ptRun(['--user' => $value]);

    expect($code)->toBe(1)
        ->and($out)->toMatch('/must be a positive integer|No user with id/')
        ->and($sender->calls)->toBe([]);
})->with(['abc', '0', '-3', '1.5', '999999']);

it('fails loudly when the user has no LIVE token (none / invalidated only)', function (): void {
    $sender = ptSender();
    $dead = UserPushToken::factory()->invalidated()->create();

    [$code, $out] = ptRun(['--user' => (string) $dead->user_id]);

    expect($code)->toBe(1)
        ->and($out)->toContain('no live push tokens')
        ->and($sender->calls)->toBe([]);
});

it('exposes no way to fan out or choose the message: no --all / --limit / --message / --title options', function (): void {
    $definition = (new PushTest)->getDefinition();

    expect(array_keys($definition->getOptions()))->not->toContain('all', 'limit', 'message', 'title', 'body')
        ->and(array_keys($definition->getOptions()))->toContain('user', 'validate-only', 'dry-run');
});

// ── --dry-run ────────────────────────────────────────────────────────────────

it('--dry-run lists the token IDS that would receive it, calls the provider NEVER, and never prints a token value', function (): void {
    $sender = ptSender();
    $token = UserPushToken::factory()->create(['token' => 'SECRET-DEVICE-TOKEN-VALUE-1']);

    [$code, $out] = ptRun(['--user' => (string) $token->user_id, '--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain('[dry-run]')
        ->and($out)->toContain('#'.$token->id)
        ->and($out)->not->toContain('SECRET-DEVICE-TOKEN-VALUE-1')
        ->and($sender->calls)->toBe([]);
});

// ── Real / validate-only sends ───────────────────────────────────────────────

it('sends ONE fixed neutral message to ONLY the named user\'s LIVE tokens', function (): void {
    $sender = ptSender();
    $mine = UserPushToken::factory()->create(['token' => 'MINE-LIVE-1']);
    $second = UserPushToken::factory()->ios()->create(['user_id' => $mine->user_id, 'token' => 'MINE-LIVE-2']);
    UserPushToken::factory()->invalidated()->create(['user_id' => $mine->user_id, 'token' => 'MINE-DEAD']);
    UserPushToken::factory()->create(['token' => 'SOMEONE-ELSES']);

    [$code, $out] = ptRun(['--user' => (string) $mine->user_id]);

    $sentTokens = array_map(static fn (array $c): string => $c['target']->token, $sender->calls);
    expect($code)->toBe(0)
        ->and($sentTokens)->toEqualCanonicalizing(['MINE-LIVE-1', 'MINE-LIVE-2'])
        ->and($sender->calls[0]['validateOnly'])->toBeFalse()
        ->and($sender->calls[0]['message']->title)->toBe('Catalyst')
        ->and($sender->calls[0]['message']->body)->toBe('Test notification')
        ->and($sender->calls[0]['message']->data)->toBe(['type' => 'push.test', 'v' => 1])
        ->and($out)->toContain('#'.$mine->id)
        ->and($out)->toContain('#'.$second->id)
        ->and($out)->not->toContain('MINE-LIVE-1')
        ->and($out)->not->toContain('MINE-LIVE-2')
        ->and($out)->not->toContain('SOMEONE-ELSES');
});

it('--validate-only with a user asks the provider to validate, not deliver', function (): void {
    $sender = ptSender();
    $token = UserPushToken::factory()->create();

    [$code, $out] = ptRun(['--user' => (string) $token->user_id, '--validate-only' => true]);

    expect($code)->toBe(0)
        ->and($sender->calls)->toHaveCount(1)
        ->and($sender->calls[0]['validateOnly'])->toBeTrue()
        ->and($out)->toContain('nothing was delivered');
});

it('a failed token makes the command exit non-zero, names the outcome, and does NOT invalidate or touch the token', function (): void {
    ptSender([PushSendResult::tokenInvalid('UNREGISTERED')]);
    $token = UserPushToken::factory()->create(['token' => 'WOULD-BE-DEAD']);
    $before = $token->fresh()?->only(['invalidated_at', 'invalidation_reason', 'last_seen_at', 'updated_at']);

    [$code, $out] = ptRun(['--user' => (string) $token->user_id]);

    expect($code)->toBe(1)
        ->and($out)->toContain('token_invalid')
        ->and($out)->toContain('UNREGISTERED')
        ->and($out)->not->toContain('WOULD-BE-DEAD')
        ->and($token->fresh()?->only(['invalidated_at', 'invalidation_reason', 'last_seen_at', 'updated_at']))->toEqual($before)
        ->and($token->fresh()?->invalidated_at)->toBeNull();
});

it('is read-only against our data: no rows are created, changed or deleted', function (): void {
    ptSender();
    $token = UserPushToken::factory()->create();
    $tables = ['user_push_tokens', 'notifications', 'user_notification_preferences', 'personal_access_tokens', 'users'];
    $snapshot = static fn (): array => array_map(
        static fn (string $t): string => md5((string) json_encode(DB::table($t)->orderBy('id')->get())),
        $tables,
    );
    $before = $snapshot();

    ptRun(['--user' => (string) $token->user_id]);
    ptRun(['--user' => (string) $token->user_id, '--validate-only' => true]);

    expect($snapshot())->toBe($before);
});

// ── Credential smoke (--validate-only, no user) ──────────────────────────────

it('credential smoke: ONE validate-only call with a placeholder token, never a stored one', function (): void {
    $sender = ptSender([PushSendResult::tokenInvalid('INVALID_ARGUMENT')]);
    $real = UserPushToken::factory()->create(['token' => 'A-REAL-DEVICE-TOKEN']);

    [$code, $out] = ptRun(['--validate-only' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain('Credential OK')
        ->and($sender->calls)->toHaveCount(1)
        ->and($sender->calls[0]['validateOnly'])->toBeTrue()
        ->and($sender->calls[0]['target']->platform)->toBe(PushPlatform::Android)
        ->and($sender->calls[0]['target']->token)->not->toBe($real->token)
        ->and($sender->calls[0]['target']->token)->toContain('placeholder');
});

it('credential smoke passes ONLY on "provider authenticated us and rejected the fake token" (or an outright accept)', function (PushSendResult $result, int $expected): void {
    ptSender([$result]);

    [$code] = ptRun(['--validate-only' => true]);

    expect($code)->toBe($expected);
})->with([
    'token invalid → authenticated' => [fn () => PushSendResult::tokenInvalid('INVALID_ARGUMENT'), 0],
    'delivered (validated)' => [fn () => PushSendResult::delivered(), 0],
    'config error (bad credential)' => [fn () => PushSendResult::configError('fcm_config', 'bad'), 1],
    'rejected (ambiguous)' => [fn () => PushSendResult::rejected('INVALID_ARGUMENT'), 1],
    'retryable (provider down / timeout)' => [fn () => PushSendResult::retryable('UNAVAILABLE'), 1],
]);

it('credential smoke prints the failing code and detail so the operator can act', function (): void {
    ptSender([PushSendResult::configError('fcm_config', 'FCM_SERVICE_ACCOUNT_JSON_B64 is not valid base64')]);

    [$code, $out] = ptRun(['--validate-only' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('did NOT pass')
        ->and($out)->toContain('config_error')
        ->and($out)->toContain('fcm_config');
});

it('the command is provider-agnostic: it names no FCM class or host (the sender is the only seam)', function (): void {
    $src = (string) file_get_contents(app_path('Console/Commands/PushTest.php'));

    expect(preg_match('/\bFcm[A-Z]\w*|googleapis|firebase/i', $src))->toBe(0);
});
