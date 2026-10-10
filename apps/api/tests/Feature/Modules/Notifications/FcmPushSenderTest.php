<?php

declare(strict_types=1);

use App\Modules\Notifications\Push\Fcm\FcmPushSender;
use App\Modules\Notifications\Push\PushMessage;
use App\Modules\Notifications\Push\PushOutcome;
use App\Modules\Notifications\Push\PushPlatform;
use App\Modules\Notifications\Push\PushPriority;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Notifications\Push\PushTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| AH-102 C2.1 — PushSender contract and the FCM v1 client
|--------------------------------------------------------------------------
|
| Everything is Http::fake'd — no network. The JWT is REAL: a throwaway RSA key
| signs it and the test verifies the RS256 signature with the public half, so a
| wrong algorithm / wrong signing input / wrong claims cannot pass.
|
| The error taxonomy is Google's documented FCM v1 vocabulary. It has NOT been
| exercised against the live API (no credentials exist yet) — these tests prove
| our MAPPING of that vocabulary, not that the vocabulary is exactly as
| documented. That gap is carried in the AH-102 review ("Not verified") and
| closed by `push:test --validate-only` + the device proof.
*/

const FCM_TEST_TOKEN = 'dEvIcE-tOkEn:APA91b-test-registration-token';
const FCM_SEND_URL = 'https://fcm.googleapis.com/v1/projects/catalyst-test/messages:send';
const FCM_OAUTH_URL = 'https://oauth2.googleapis.com/token';

/** @return array{private: string, public: string} one throwaway RSA keypair per run */
function fcmKeypair(): array
{
    static $pair = null;

    if ($pair === null) {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        assert($res !== false);
        openssl_pkey_export($res, $private);
        $details = openssl_pkey_get_details($res);
        assert(is_array($details));
        $pair = ['private' => (string) $private, 'public' => (string) $details['key']];
    }

    return $pair;
}

/** @param array<string, mixed> $overrides merged over a valid service-account JSON */
function fcmCredential(array $overrides = []): string
{
    return base64_encode((string) json_encode(array_merge([
        'type' => 'service_account',
        'project_id' => 'catalyst-test',
        'client_email' => 'push@catalyst-test.iam.gserviceaccount.com',
        'private_key' => fcmKeypair()['private'],
        'token_uri' => 'https://evil.example.test/steal',
    ], $overrides)));
}

function fcmConfigure(?string $b64 = null, ?string $projectId = 'catalyst-test'): void
{
    config([
        'integrations.push.fcm.service_account_json_b64' => $b64 ?? fcmCredential(),
        'integrations.push.fcm.project_id' => $projectId,
        'integrations.push.fcm.timeout' => 5,
    ]);
}

function fcmMessage(?PushMessage $m = null): PushMessage
{
    return $m ?? new PushMessage(title: 'Title', body: 'Body', data: ['type' => 'assignment.invited', 'v' => 1]);
}

function fcmTarget(): PushTarget
{
    return new PushTarget(PushPlatform::Android, FCM_TEST_TOKEN);
}

function fcmSender(): PushSender
{
    return app(PushSender::class);
}

/** @return array<string, mixed> */
function fcmOauthOk(string $token = 'ya29.access-1', int $expiresIn = 3600): array
{
    return ['access_token' => $token, 'expires_in' => $expiresIn, 'token_type' => 'Bearer'];
}

/** @return array<string, mixed> */
function fcmError(int $http, string $status, ?string $fcmCode, string $message = 'boom'): array
{
    $error = ['code' => $http, 'message' => $message, 'status' => $status];

    if ($fcmCode !== null) {
        $error['details'] = [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $fcmCode]];
    }

    return ['error' => $error];
}

/** @return list<Request> */
function fcmRequestsTo(string $url): array
{
    $out = [];
    foreach (Http::recorded() as [$request]) {
        if ($request->url() === $url) {
            $out[] = $request;
        }
    }

    return $out;
}

function fcmSendBody(): array
{
    $sent = fcmRequestsTo(FCM_SEND_URL);
    expect($sent)->not->toBeEmpty();

    /** @var array<string, mixed> $body */
    $body = $sent[0]->data();

    return $body;
}

beforeEach(function (): void {
    Cache::flush();
    fcmConfigure();
});

// =============================================================================
// Binding + delivery
// =============================================================================

it('the container resolves PushSender to the FCM client (the single provider binding)', function (): void {
    expect(fcmSender())->toBeInstanceOf(FcmPushSender::class);
});

it('a 200 from FCM is Delivered', function (): void {
    Http::fake([FCM_OAUTH_URL => Http::response(fcmOauthOk()), FCM_SEND_URL => Http::response(['name' => 'projects/x/messages/1'])]);

    $result = fcmSender()->send(fcmMessage(), fcmTarget());

    expect($result->outcome)->toBe(PushOutcome::Delivered)
        ->and($result->isDelivered())->toBeTrue();
});

it('sends to the project URL with the OAuth bearer and the neutral message mapped into FCM v1 JSON', function (): void {
    Http::fake([FCM_OAUTH_URL => Http::response(fcmOauthOk('ya29.bearer-xyz')), FCM_SEND_URL => Http::response([])]);

    fcmSender()->send(new PushMessage(
        title: 'New invitation',
        body: 'Acme invited you',
        data: ['type' => 'assignment.invited', 'v' => 1, 'notification_ulid' => '01ABC'],
        collapseKey: 'assignment:01XYZ',
        channelId: 'activity',
        ttlSeconds: 3600,
        priority: PushPriority::High,
    ), fcmTarget());

    $sent = fcmRequestsTo(FCM_SEND_URL)[0];
    $m = fcmSendBody()['message'];

    expect($sent->hasHeader('Authorization', 'Bearer ya29.bearer-xyz'))->toBeTrue()
        ->and($m['token'])->toBe(FCM_TEST_TOKEN)
        ->and($m['notification'])->toBe(['title' => 'New invitation', 'body' => 'Acme invited you'])
        ->and($m['data'])->toBe(['type' => 'assignment.invited', 'v' => '1', 'notification_ulid' => '01ABC'], 'data values are strings')
        ->and($m['android']['priority'])->toBe('HIGH')
        ->and($m['android']['ttl'])->toBe('3600s')
        ->and($m['android']['collapse_key'])->toBe('assignment:01XYZ')
        ->and($m['android']['notification'])->toBe(['channel_id' => 'activity', 'tag' => 'assignment:01XYZ'])
        ->and($m['apns']['headers']['apns-collapse-id'])->toBe('assignment:01XYZ')
        ->and($m['apns']['headers']['apns-priority'])->toBe('10')
        ->and((int) $m['apns']['headers']['apns-expiration'])->toBeGreaterThan(time() + 3500)
        ->and($m['apns']['payload']['aps']['thread-id'])->toBe('assignment:01XYZ')
        ->and(fcmSendBody())->not->toHaveKey('validate_only');
});

it('omits body, data and every collapse field when the message has none; normal priority maps down', function (): void {
    Http::fake([FCM_OAUTH_URL => Http::response(fcmOauthOk()), FCM_SEND_URL => Http::response([])]);

    fcmSender()->send(new PushMessage(title: 'Only a title', priority: PushPriority::Normal), fcmTarget());

    $m = fcmSendBody()['message'];
    expect($m['notification'])->toBe(['title' => 'Only a title'])
        ->and($m)->not->toHaveKey('data')
        ->and($m['android'])->not->toHaveKey('collapse_key')
        ->and($m['android']['notification'])->not->toHaveKey('tag')
        ->and($m['android']['priority'])->toBe('NORMAL')
        ->and($m['apns']['headers'])->not->toHaveKey('apns-collapse-id')
        ->and($m['apns']['headers']['apns-priority'])->toBe('5');
});

it('a collapse key over the 64-byte vendor limit is replaced by a stable short digest (same key → same digest)', function (): void {
    Http::fake([FCM_OAUTH_URL => Http::response(fcmOauthOk()), FCM_SEND_URL => Http::response([])]);
    $long = str_repeat('k', 100);

    fcmSender()->send(new PushMessage(title: 't', collapseKey: $long), fcmTarget());
    fcmSender()->send(new PushMessage(title: 't', collapseKey: $long), fcmTarget());

    $sent = fcmRequestsTo(FCM_SEND_URL);
    $a = $sent[0]->data()['message']['android']['collapse_key'];
    $b = $sent[1]->data()['message']['android']['collapse_key'];
    expect(strlen($a))->toBeLessThanOrEqual(64)->and($a)->toBe($b)->and($a)->not->toBe($long);
});

it('validateOnly asks FCM to validate without delivering', function (): void {
    Http::fake([FCM_OAUTH_URL => Http::response(fcmOauthOk()), FCM_SEND_URL => Http::response([])]);

    $result = fcmSender()->send(fcmMessage(), fcmTarget(), validateOnly: true);

    expect($result->isDelivered())->toBeTrue()
        ->and(fcmSendBody()['validate_only'])->toBeTrue();
});

// =============================================================================
// The OAuth assertion — real RS256, verified
// =============================================================================

it('signs a REAL RS256 JWT: the signature verifies with the public key and the claims are exactly right', function (): void {
    Http::fake([FCM_OAUTH_URL => Http::response(fcmOauthOk()), FCM_SEND_URL => Http::response([])]);

    $before = time();
    fcmSender()->send(fcmMessage(), fcmTarget());

    $oauth = fcmRequestsTo(FCM_OAUTH_URL);
    expect($oauth)->toHaveCount(1);
    $form = $oauth[0]->data();
    expect($form['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer');

    [$h, $c, $s] = explode('.', (string) $form['assertion']);
    $unb64 = fn (string $v): string => (string) base64_decode(strtr($v, '-_', '+/'), true);

    expect(json_decode($unb64($h), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT']);

    $claims = json_decode($unb64($c), true);
    expect($claims['iss'])->toBe('push@catalyst-test.iam.gserviceaccount.com')
        ->and($claims['scope'])->toBe('https://www.googleapis.com/auth/firebase.messaging')
        ->and($claims['aud'])->toBe(FCM_OAUTH_URL)
        ->and($claims['iat'])->toBeGreaterThanOrEqual($before)
        ->and($claims['exp'] - $claims['iat'])->toBe(3600);

    $verified = openssl_verify($h.'.'.$c, $unb64($s), fcmKeypair()['public'], OPENSSL_ALGO_SHA256);
    expect($verified)->toBe(1, 'RS256 signature must verify against the public key over "header.claims"');

    // base64url, unpadded
    expect($form['assertion'])->not->toMatch('/[+\/=]/');
});

it('the signed assertion goes to the FIXED token URL — never the credential JSON\'s token_uri', function (): void {
    Http::fake([FCM_OAUTH_URL => Http::response(fcmOauthOk()), FCM_SEND_URL => Http::response([])]);

    fcmSender()->send(fcmMessage(), fcmTarget());

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'evil.example.test'));
    expect(fcmRequestsTo(FCM_OAUTH_URL))->toHaveCount(1);
});

it('the access token is cached: two sends cost ONE token exchange', function (): void {
    Http::fake([FCM_OAUTH_URL => Http::response(fcmOauthOk()), FCM_SEND_URL => Http::response([])]);

    fcmSender()->send(fcmMessage(), fcmTarget());
    fcmSender()->send(fcmMessage(), fcmTarget());

    expect(fcmRequestsTo(FCM_OAUTH_URL))->toHaveCount(1)->and(fcmRequestsTo(FCM_SEND_URL))->toHaveCount(2);
});

it('the cache TTL is expires_in minus the safety margin: a send after that re-exchanges', function (): void {
    Http::fake([FCM_OAUTH_URL => Http::response(fcmOauthOk('ya29.t', 600)), FCM_SEND_URL => Http::response([])]);

    fcmSender()->send(fcmMessage(), fcmTarget());
    $this->travel(479)->seconds(); // 600 - 120 = 480 s cached
    fcmSender()->send(fcmMessage(), fcmTarget());
    expect(fcmRequestsTo(FCM_OAUTH_URL))->toHaveCount(1);

    $this->travel(2)->seconds(); // now past 480 s
    fcmSender()->send(fcmMessage(), fcmTarget());
    expect(fcmRequestsTo(FCM_OAUTH_URL))->toHaveCount(2);
});

it('a 401 from FCM forces ONE token refresh and retries the send once → Delivered', function (): void {
    Http::fake([
        FCM_OAUTH_URL => Http::sequence()->push(fcmOauthOk('stale'))->push(fcmOauthOk('fresh')),
        FCM_SEND_URL => Http::sequence()->push(fcmError(401, 'UNAUTHENTICATED', null), 401)->push(['name' => 'ok']),
    ]);

    $result = fcmSender()->send(fcmMessage(), fcmTarget());

    expect($result->outcome)->toBe(PushOutcome::Delivered)
        ->and(fcmRequestsTo(FCM_OAUTH_URL))->toHaveCount(2)
        ->and(fcmRequestsTo(FCM_SEND_URL)[1]->hasHeader('Authorization', 'Bearer fresh'))->toBeTrue();
});

it('a 401 that persists after the refresh is ConfigError — and the loop stops (exactly 2 sends)', function (): void {
    Http::fake([
        FCM_OAUTH_URL => Http::response(fcmOauthOk()),
        FCM_SEND_URL => Http::response(fcmError(401, 'UNAUTHENTICATED', null), 401),
    ]);

    $result = fcmSender()->send(fcmMessage(), fcmTarget());

    expect($result->outcome)->toBe(PushOutcome::ConfigError)
        ->and(fcmRequestsTo(FCM_SEND_URL))->toHaveCount(2);
});

// =============================================================================
// Error taxonomy — the mapping table
// =============================================================================

it('maps FCM errors to outcomes (the taxonomy table)', function (int $http, array $body, PushOutcome $expected, string $code): void {
    Http::fake([FCM_OAUTH_URL => Http::response(fcmOauthOk()), FCM_SEND_URL => Http::response($body, $http)]);

    $result = fcmSender()->send(fcmMessage(), fcmTarget());

    expect($result->outcome)->toBe($expected)
        ->and($result->code)->toBe($code)
        ->and($result->httpStatus)->toBe($http);
})->with([
    'UNREGISTERED → TokenInvalid' => [404, fcmError(404, 'NOT_FOUND', 'UNREGISTERED', 'Requested entity was not found.'), PushOutcome::TokenInvalid, 'UNREGISTERED'],
    'INVALID_ARGUMENT naming the token → TokenInvalid' => [400, fcmError(400, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT', 'The registration token is not a valid FCM registration token'), PushOutcome::TokenInvalid, 'INVALID_ARGUMENT'],
    'INVALID_ARGUMENT about something else → Rejected (OUR payload, never a token kill)' => [400, fcmError(400, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT', 'Invalid value at message.data (map)'), PushOutcome::Rejected, 'INVALID_ARGUMENT'],
    'SENDER_ID_MISMATCH → ConfigError' => [403, fcmError(403, 'PERMISSION_DENIED', 'SENDER_ID_MISMATCH'), PushOutcome::ConfigError, 'SENDER_ID_MISMATCH'],
    'THIRD_PARTY_AUTH_ERROR → ConfigError' => [401, fcmError(401, 'UNAUTHENTICATED', 'THIRD_PARTY_AUTH_ERROR'), PushOutcome::ConfigError, 'THIRD_PARTY_AUTH_ERROR'],
    'QUOTA_EXCEEDED → Retryable' => [429, fcmError(429, 'RESOURCE_EXHAUSTED', 'QUOTA_EXCEEDED'), PushOutcome::Retryable, 'QUOTA_EXCEEDED'],
    'UNAVAILABLE → Retryable' => [503, fcmError(503, 'UNAVAILABLE', 'UNAVAILABLE'), PushOutcome::Retryable, 'UNAVAILABLE'],
    'INTERNAL → Retryable' => [500, fcmError(500, 'INTERNAL', 'INTERNAL'), PushOutcome::Retryable, 'INTERNAL'],
    '403 with no FCM code → ConfigError (API disabled / no permission)' => [403, fcmError(403, 'PERMISSION_DENIED', null), PushOutcome::ConfigError, 'http_403'],
    '404 WITHOUT the UNREGISTERED detail → ConfigError (wrong project), never a token kill' => [404, fcmError(404, 'NOT_FOUND', null, 'Requested entity was not found.'), PushOutcome::ConfigError, 'http_404'],
    'bare 429 → Retryable' => [429, [], PushOutcome::Retryable, 'http_429'],
    'bare 502 → Retryable' => [502, [], PushOutcome::Retryable, 'http_502'],
    'bare 400 → Rejected' => [400, fcmError(400, 'FAILED_PRECONDITION', null), PushOutcome::Rejected, 'http_400'],
    'bare 422 → Rejected' => [422, [], PushOutcome::Rejected, 'http_422'],
]);

it('a connection failure / timeout is Retryable', function (): void {
    Http::fake([
        FCM_OAUTH_URL => Http::response(fcmOauthOk()),
        FCM_SEND_URL => fn () => throw new ConnectionException('cURL error 28: timed out'),
    ]);

    $result = fcmSender()->send(fcmMessage(), fcmTarget());

    expect($result->outcome)->toBe(PushOutcome::Retryable)->and($result->code)->toBe('fcm_connection');
});

it('the error detail is truncated and NEVER contains the device token', function (): void {
    Http::fake([
        FCM_OAUTH_URL => Http::response(fcmOauthOk()),
        FCM_SEND_URL => Http::response(fcmError(400, 'INVALID_ARGUMENT', 'INVALID_ARGUMENT', 'bad '.FCM_TEST_TOKEN.' '.str_repeat('x', 500)), 400),
    ]);

    $result = fcmSender()->send(fcmMessage(), fcmTarget());

    expect($result->detail)->not->toContain(FCM_TEST_TOKEN)
        ->and($result->detail)->toContain('[token]')
        ->and(mb_strlen((string) $result->detail))->toBeLessThanOrEqual(200);
});

// =============================================================================
// Config / credential failures — a ConfigError result, never an exception, never a request
// =============================================================================

it('unusable credentials/config are a ConfigError and make NO network call', function (?string $b64, ?string $projectId): void {
    config(['integrations.push.fcm.service_account_json_b64' => $b64, 'integrations.push.fcm.project_id' => $projectId]);
    Http::fake();

    $result = fcmSender()->send(fcmMessage(), fcmTarget());

    expect($result->outcome)->toBe(PushOutcome::ConfigError)->and($result->code)->toBe('fcm_config');
    Http::assertNothingSent();
})->with([
    'unset' => [null, 'catalyst-test'],
    'empty string' => ['', 'catalyst-test'],
    'whitespace' => ['   ', 'catalyst-test'],
    'not base64' => ['!!!not base64!!!', 'catalyst-test'],
    'base64 of non-JSON' => [base64_encode('this is not json'), 'catalyst-test'],
    'base64 of a JSON scalar' => [base64_encode('"just a string"'), 'catalyst-test'],
    'JSON without client_email' => [base64_encode((string) json_encode(['private_key' => 'k', 'project_id' => 'p'])), 'catalyst-test'],
    'JSON without private_key' => [base64_encode((string) json_encode(['client_email' => 'a@b', 'project_id' => 'p'])), 'catalyst-test'],
]);

it('a private_key that is not a signable RSA key is a ConfigError (no network call)', function (): void {
    fcmConfigure(fcmCredential(['private_key' => "-----BEGIN PRIVATE KEY-----\nnot-a-key\n-----END PRIVATE KEY-----\n"]));
    Http::fake();

    $result = fcmSender()->send(fcmMessage(), fcmTarget());

    expect($result->outcome)->toBe(PushOutcome::ConfigError);
    Http::assertNothingSent();
});

it('no project id anywhere (env empty AND credential has none) is a ConfigError', function (): void {
    fcmConfigure(fcmCredential(['project_id' => null]), projectId: null);
    Http::fake();

    $result = fcmSender()->send(fcmMessage(), fcmTarget());

    expect($result->outcome)->toBe(PushOutcome::ConfigError);
    Http::assertNothingSent();
});

it('FCM_PROJECT_ID falls back to the credential\'s project_id when the env is empty', function (): void {
    fcmConfigure(fcmCredential(['project_id' => 'from-json']), projectId: null);
    Http::fake([FCM_OAUTH_URL => Http::response(fcmOauthOk()), '*' => Http::response([])]);

    fcmSender()->send(fcmMessage(), fcmTarget());

    expect(fcmRequestsTo('https://fcm.googleapis.com/v1/projects/from-json/messages:send'))->toHaveCount(1);
});

it('the OAuth endpoint rejecting the credential (invalid_grant, 4xx) is ConfigError; its 5xx / timeout is Retryable', function (mixed $oauthResponse, PushOutcome $expected): void {
    Http::fake([FCM_OAUTH_URL => $oauthResponse instanceof Closure ? $oauthResponse : Http::response(...$oauthResponse)]);

    expect(fcmSender()->send(fcmMessage(), fcmTarget())->outcome)->toBe($expected);
    Http::assertNotSent(fn (Request $r): bool => $r->url() === FCM_SEND_URL);
})->with([
    'invalid_grant 400' => [[['error' => 'invalid_grant'], 400], PushOutcome::ConfigError],
    'invalid_client 401' => [[['error' => 'invalid_client'], 401], PushOutcome::ConfigError],
    '503' => [[[], 503], PushOutcome::Retryable],
    '429' => [[[], 429], PushOutcome::Retryable],
    'timeout' => [fn () => throw new ConnectionException('timed out'), PushOutcome::Retryable],
]);

it('the OAuth endpoint returning no access_token is a ConfigError', function (): void {
    Http::fake([FCM_OAUTH_URL => Http::response(['expires_in' => 3600])]);

    expect(fcmSender()->send(fcmMessage(), fcmTarget())->outcome)->toBe(PushOutcome::ConfigError);
});

it('never lets a credential fragment into a result: private key, client email and access token are absent from every outcome', function (): void {
    Http::fake([
        FCM_OAUTH_URL => Http::response(fcmOauthOk('ya29.SECRET-ACCESS')),
        FCM_SEND_URL => Http::response(fcmError(500, 'INTERNAL', 'INTERNAL', 'oops'), 500),
    ]);

    $result = fcmSender()->send(fcmMessage(), fcmTarget());
    $dump = json_encode($result);

    expect($dump)->not->toContain('SECRET-ACCESS')
        ->and($dump)->not->toContain('BEGIN PRIVATE KEY')
        ->and($dump)->not->toContain('gserviceaccount');
});

// =============================================================================
// Containment (the fallback-B guarantee)
// =============================================================================

/** @return list<string> */
function fcmPhpFilesOutsideFcmDir(): array
{
    $root = app_path('Modules');
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($it as $file) {
        $path = str_replace('\\', '/', (string) $file);
        if (str_ends_with($path, '.php') && ! str_contains($path, '/Notifications/Push/Fcm/')) {
            $files[] = $path;
        }
    }

    return $files;
}

it('CONTAINMENT: nothing outside Notifications/Push/Fcm/ names an Fcm class or the FCM host (except the one provider binding)', function (): void {
    $offenders = [];

    foreach (fcmPhpFilesOutsideFcmDir() as $path) {
        if (str_ends_with($path, '/Notifications/NotificationsServiceProvider.php')) {
            continue; // THE single binding site — pinned by the next test.
        }

        $src = (string) file_get_contents($path);

        if (preg_match('/\bFcm[A-Z]\w*|fcm\.googleapis\.com|oauth2\.googleapis\.com|firebase\.messaging/', $src) === 1) {
            $offenders[] = str_replace(app_path().'/', '', $path);
        }
    }

    expect($offenders)->toBe([], 'FCM details leaked outside Push/Fcm: '.implode(', ', $offenders));
});

it('CONTAINMENT: the provider binding is the only non-Fcm file naming FcmPushSender, and it binds the interface', function (): void {
    $provider = (string) file_get_contents(app_path('Modules/Notifications/NotificationsServiceProvider.php'));

    expect($provider)->toContain('bind(PushSender::class, FcmPushSender::class)');
});

it('CONTAINMENT scanner is not vacuous: the Fcm directory itself DOES name those strings, and the scan sees real files', function (): void {
    $fcmSrc = '';
    foreach (glob(app_path('Modules/Notifications/Push/Fcm/*.php')) ?: [] as $f) {
        $fcmSrc .= (string) file_get_contents($f);
    }

    expect($fcmSrc)->toContain('Fcm')->and($fcmSrc)->toContain('googleapis.com')
        ->and(count(fcmPhpFilesOutsideFcmDir()))->toBeGreaterThan(100);
});
