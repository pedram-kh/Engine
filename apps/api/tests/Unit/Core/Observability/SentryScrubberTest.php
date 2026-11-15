<?php

declare(strict_types=1);

use App\Core\Observability\SentryScrubber;
use Illuminate\Database\QueryException;
use Sentry\Breadcrumb;
use Sentry\Context\OsContext;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Stacktrace;
use Sentry\UserDataBag;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
|--------------------------------------------------------------------------
| SentryScrubber (AH-121, docs/reviews/sentry-plan.md B1 and B7)
|--------------------------------------------------------------------------
|
| Every assertion serialises the scrubbed event to the real Sentry payload
| JSON and searches it, so a field nobody thought of still fails.
*/

const SCRUB_ULID = '01JABCDEFGHJKMNPQRSTVWXYZ0';
const SCRUB_EMAIL = 'marisol.quintanilla@example.com';
const SCRUB_SANCTUM = '17|aB3dE5gH7jK9mN1pQ3rS5tU7vW9xY1zA3bC5dE7f';
const SCRUB_BEARER = 'Bearer eyJhbGciOiJIUzI1NiJ9.payloadpart.signaturepart';
const SCRUB_OPAQUE = 'Zk3Jq9Wm2Lp8Xc5Vb7Nn4Hg6Fd1Sa0Qw3Er5Ty7Ui9Op2Lk4Jh6Gf8Ds1Aa3Zx5C';
const SCRUB_PHONE = '+34 612 345 678';
const SCRUB_NAME = 'Marisol Quintanilla';

/**
 * The serialised Sentry event body (the real wire format, third line of the
 * envelope) as one JSON string, slashes unescaped so paths can be searched.
 */
function scrubPayload(Event $event): string
{
    $lines = explode("\n", (new PayloadSerializer(new Options))->serialize($event));

    return (string) json_encode(json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function scrubEventWith(callable $configure): Event
{
    $event = Event::createEvent();
    $configure($event);

    $scrubbed = SentryScrubber::beforeSend($event);
    if ($scrubbed === null) {
        throw new RuntimeException('scrubber unexpectedly dropped the event');
    }

    return $scrubbed;
}

/** Assert none of the needles appears anywhere in the serialised payload. */
function scrubAbsent(string $json, string ...$needles): void
{
    foreach ($needles as $needle) {
        expect($json)->not->toContain($needle);
    }
}

function scrubExceptionBag(Throwable $e, ?Stacktrace $trace = null): ExceptionDataBag
{
    return new ExceptionDataBag($e, $trace);
}

it('redacts emails in the message, exception value, breadcrumb, tag, extra, context and transaction', function (): void {
    $event = scrubEventWith(function (Event $e): void {
        $e->setMessage('failed for '.SCRUB_EMAIL);
        $e->setExceptions([scrubExceptionBag(new RuntimeException('no row for '.SCRUB_EMAIL))]);
        $e->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'app', 'sent to '.SCRUB_EMAIL)]);
        $e->setTags(['who' => SCRUB_EMAIL]);
        $e->setExtra(['nested' => ['mail' => SCRUB_EMAIL]]);
        $e->setContext('custom', ['mail' => SCRUB_EMAIL]);
        $e->setTransaction('GET /u/'.SCRUB_EMAIL);
    });

    scrubAbsent(scrubPayload($event), SCRUB_EMAIL, 'example.com');
});

it('redacts bearer tokens, sanctum tokens, secret pairs and opaque tokens', function (): void {
    $event = scrubEventWith(function (Event $e): void {
        $e->setMessage('auth '.SCRUB_BEARER.' and '.SCRUB_SANCTUM);
        $e->setExceptions([scrubExceptionBag(new RuntimeException(
            'GET /reset?token=abc123def&password=hunter2secret&code=998877 and '.SCRUB_OPAQUE,
        ))]);
        $e->setExtra(['url' => 'https://x.test/a?signature=deadbeef&X-Amz-Signature=cafebabe&X-Amz-Credential=AKIAEXAMPLE']);
    });

    scrubAbsent(scrubPayload($event), 'eyJhbGciOiJIUzI1NiJ9', 'aB3dE5gH7jK9mN1pQ3rS5tU7vW9xY1zA3bC5dE7f', 'abc123def', 'hunter2secret', '998877', SCRUB_OPAQUE, 'deadbeef', 'cafebabe', 'AKIAEXAMPLE');
});

it('deletes sensitive headers and drops request body, cookies, query string and env', function (): void {
    $event = scrubEventWith(function (Event $e): void {
        $e->setRequest([
            'url' => 'https://api.example.test/v1/me?token=abc123def#frag',
            'method' => 'POST',
            'query_string' => 'token=abc123def',
            'data' => ['password' => 'hunter2secret'],
            'cookies' => ['web_admin' => 'sessionvalue123', 'catalyst_main_session' => 'sessionvalue456'],
            'env' => ['REMOTE_ADDR' => '203.0.113.9'],
            'headers' => [
                'Authorization' => SCRUB_BEARER,
                'Cookie' => 'web_admin=sessionvalue123',
                'Set-Cookie' => 'web_admin=sessionvalue123',
                'X-XSRF-TOKEN' => 'xsrfvalue',
                'X-CSRF-TOKEN' => 'csrfvalue',
                'X-Test-Helpers-Token' => 'helpersvalue',
                'Referer' => 'https://app.example.test/page?email=a@b.co&t=1',
                'User-Agent' => 'Mozilla/5.0',
            ],
        ]);
    });

    $json = scrubPayload($event);

    expect($json)->not->toContain('abc123def');
    expect($json)->not->toContain('hunter2secret');
    expect($json)->not->toContain('sessionvalue');
    expect($json)->not->toContain('203.0.113.9');
    expect($json)->not->toContain('xsrfvalue');
    expect($json)->not->toContain('csrfvalue');
    expect($json)->not->toContain('helpersvalue');
    expect($json)->not->toContain('eyJhbGciOiJIUzI1NiJ9');
    expect($json)->not->toContain('email=');
    expect($json)->not->toContain('#frag');
    expect($json)->toContain('Mozilla/5.0');

    expect($event->getRequest()['url'])->toBe('https://api.example.test/v1/me');
});

it('strips query and fragment from URLs in request, breadcrumbs and free text', function (): void {
    $event = scrubEventWith(function (Event $e): void {
        $e->setMessage('fetched https://bucket.s3.eu-central-1.amazonaws.com/k?X-Amz-Signature=sig123abc&X-Amz-Credential=cred456 now');
        $e->setBreadcrumb([
            new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'navigation', null, [
                'from' => '/home?x=1#y',
                'to' => '/sign-in?redirect=/',
                'url' => 'https://api.example.test/a?token=zzz',
                'http.query' => 'token=zzz',
            ]),
        ]);
    });

    $json = scrubPayload($event);

    scrubAbsent($json, 'sig123abc', 'cred456', 'redirect=', 'x=1', 'zzz');

    $meta = $event->getBreadcrumbs()[0]->getMetadata();
    expect($meta['to'])->toBe('/sign-in')
        ->and($meta['from'])->toBe('/home')
        ->and($meta)->not->toHaveKey('http.query');
});

it('before_breadcrumb scrubs a breadcrumb on its own', function (): void {
    $crumb = SentryScrubber::beforeBreadcrumb(
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'navigation', 'to '.SCRUB_EMAIL, ['to' => '/sign-in?redirect=/']),
    );

    expect($crumb->getMessage())->not->toContain(SCRUB_EMAIL)
        ->and($crumb->getMetadata()['to'])->toBe('/sign-in');
});

it('rebuilds the user block as id only, whatever else was set', function (): void {
    $event = scrubEventWith(function (Event $e): void {
        $user = UserDataBag::createFromUserIdentifier(SCRUB_ULID);
        $user->setEmail(SCRUB_EMAIL);
        $user->setUsername('marisol');
        $user->setIpAddress('203.0.113.9');
        $user->setSegment('vip');
        $user->setMetadata('name', SCRUB_NAME);
        $e->setUser($user);
    });

    $payload = json_decode(scrubPayload($event), true, 512, JSON_THROW_ON_ERROR);

    // The serializer writes unset keys as null; a null key carries no data.
    $present = array_keys(array_filter($payload['user'], static fn ($v): bool => $v !== null));

    expect($present)->toBe(['id'])
        ->and($payload['user']['id'])->toBe(SCRUB_ULID);
    scrubAbsent(scrubPayload($event), SCRUB_EMAIL, 'marisol', '203.0.113.9', SCRUB_NAME);
});

it('removes the user block when the id is not a ulid (install id, numeric id, email)', function (string $id): void {
    $event = scrubEventWith(function (Event $e) use ($id): void {
        $e->setUser(UserDataBag::createFromUserIdentifier($id));
    });

    $payload = json_decode(scrubPayload($event), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->not->toHaveKey('user');
})->with([
    'install id' => '5E415BF1-0000-4000-8000-000000000000',
    'numeric id' => '42',
    'email as id' => SCRUB_EMAIL,
]);

it('drops frame variables and source context', function (): void {
    $frame = new Frame('handle', '/app/Foo.php', 10, null, '/app/Foo.php', ['model' => SCRUB_NAME], true);
    $frame->setContextLine('$x = "'.SCRUB_EMAIL.'";');
    $frame->setPreContext(['// '.SCRUB_EMAIL]);
    $frame->setPostContext(['// '.SCRUB_PHONE]);

    $event = scrubEventWith(function (Event $e) use ($frame): void {
        $e->setExceptions([scrubExceptionBag(new RuntimeException('boom'), new Stacktrace([$frame]))]);
    });

    $json = scrubPayload($event);
    expect($json)->not->toContain(SCRUB_NAME);
    expect($json)->not->toContain(SCRUB_EMAIL);
    expect($json)->not->toContain(SCRUB_PHONE);
    expect($json)->toContain('/app/Foo.php');
});

it('keeps class and frames but drops the message for the erasure namespace', function (): void {
    $frame = new Frame('App\\Modules\\Erasure\\Jobs\\RunErasureJob::handle', '/app/app/Modules/Erasure/Jobs/RunErasureJob.php', 77, 'App\\Modules\\Erasure\\Jobs\\RunErasureJob::handle');

    $event = scrubEventWith(function (Event $e) use ($frame): void {
        $e->setExceptions([scrubExceptionBag(
            new RuntimeException('could not anonymise '.SCRUB_NAME.' '.SCRUB_PHONE),
            new Stacktrace([$frame]),
        )]);
    });

    $json = scrubPayload($event);

    expect($json)->not->toContain(SCRUB_NAME);
    expect($json)->not->toContain(SCRUB_PHONE);
    expect($json)->not->toContain('could not anonymise');
    expect($json)->toContain('RuntimeException');
    expect($json)->toContain('RunErasureJob.php');
});

it('sends only the class and SQLSTATE for a QueryException holding a name and a phone number', function (): void {
    $pdo = new PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key (phone)=('.SCRUB_PHONE.')');
    $pdo->errorInfo = ['23505', 7, 'duplicate key'];
    $query = new QueryException(
        'pgsql',
        'insert into creators (name, phone) values (?, ?)',
        [SCRUB_NAME, SCRUB_PHONE],
        $pdo,
    );

    $event = scrubEventWith(function (Event $e) use ($query, $pdo): void {
        // Sentry orders previous exceptions first.
        $e->setExceptions([scrubExceptionBag($pdo), scrubExceptionBag($query)]);
    });

    $json = scrubPayload($event);

    expect($json)->not->toContain(SCRUB_NAME);
    expect($json)->not->toContain(SCRUB_PHONE);
    expect($json)->not->toContain('612 345');
    expect($json)->not->toContain('insert into');
    expect($json)->not->toContain('creators');
    expect($json)->toContain('QueryException (SQLSTATE 23505)');
    expect($json)->toContain('PDOException (SQLSTATE 23505)');
});

it('returns null for every HttpException below 500 and keeps 500 and above', function (int $status, bool $dropped): void {
    $event = Event::createEvent();
    $result = SentryScrubber::beforeSend($event, EventHint::fromArray(['exception' => new HttpException($status)]));

    $dropped ? expect($result)->toBeNull() : expect($result)->not->toBeNull();
})->with([
    [401, true], [403, true], [404, true], [405, true], [409, true], [422, true], [429, true], [499, true],
    [500, false], [503, false],
]);

it('scrubs a combined payload so that no planted secret survives anywhere in the JSON', function (): void {
    $event = scrubEventWith(function (Event $e): void {
        $e->setMessage(SCRUB_EMAIL.' '.SCRUB_BEARER.' '.SCRUB_SANCTUM);
        $e->setExceptions([scrubExceptionBag(new RuntimeException(SCRUB_EMAIL.' '.SCRUB_OPAQUE))]);
        $e->setTags(['k' => SCRUB_EMAIL]);
        $e->setExtra(['k' => ['deep' => SCRUB_SANCTUM]]);
        $e->setRequest(['url' => 'https://a.test/x?token=planted', 'headers' => ['Authorization' => SCRUB_BEARER, 'Cookie' => 'planted_cookie=1']]);
        $user = UserDataBag::createFromUserIdentifier(SCRUB_ULID);
        $user->setEmail(SCRUB_EMAIL);
        $e->setUser($user);
    });

    $json = scrubPayload($event);

    foreach ([SCRUB_EMAIL, SCRUB_BEARER, 'eyJhbGciOiJIUzI1NiJ9', SCRUB_SANCTUM, 'aB3dE5gH7jK9mN1pQ3rS5tU7vW9xY1zA3bC5dE7f', SCRUB_OPAQUE, 'planted'] as $secret) {
        expect($json)->not->toContain($secret);
    }
});

it('drops the OS kernel version (it repeats the hostname) and keeps the os name and version', function (): void {
    $event = scrubEventWith(function (Event $e): void {
        $e->setOsContext(new OsContext('Darwin', '25.1.0', 'build-1', 'Darwin Pedrams-Mac-Mini.local 25.1.0 arm64', 'arm64'));
    });
    $json = scrubPayload($event);

    scrubAbsent($json, 'Pedrams-Mac-Mini');
    expect($event->getOsContext()?->getKernelVersion())->toBeNull()
        ->and($event->getOsContext()?->getName())->toBe('Darwin')
        ->and($event->getOsContext()?->getVersion())->toBe('25.1.0');
});
