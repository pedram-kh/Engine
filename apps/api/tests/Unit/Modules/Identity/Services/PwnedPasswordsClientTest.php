<?php

declare(strict_types=1);

use App\Modules\Identity\Services\PwnedPasswordsClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set('services.hibp.url', 'https://api.pwnedpasswords.com');
    config()->set('services.hibp.timeout', 3);
});

it('sends ONLY the first 5 hex characters of the SHA-1 hash', function (): void {
    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response("0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n", 200),
    ]);

    /** @var PwnedPasswordsClient $client */
    $client = app(PwnedPasswordsClient::class);
    $client->breachCount('password');

    Http::assertSent(function (HttpRequest $request): bool {
        $url = $request->url();

        // The URL must end at /range/<five-hex>. Anything longer would mean
        // we leaked more than the documented k-anonymity prefix.
        if (preg_match('#/range/([0-9A-F]+)$#', $url, $matches) !== 1) {
            return false;
        }

        return strlen($matches[1]) === 5;
    });
});

it('never sends the plaintext password anywhere on the wire', function (): void {
    $secret = 'correct horse battery staple';

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    app(PwnedPasswordsClient::class)->breachCount($secret);

    Http::assertSent(function (HttpRequest $request) use ($secret): bool {
        return ! str_contains($request->url(), $secret)
            && ! str_contains($request->body() ?: '', $secret)
            && ! str_contains(json_encode($request->headers()) ?: '', $secret);
    });
});

it('never sends the full SHA-1 hash on the wire', function (): void {
    $password = 'P@ssw0rdSomething';
    $fullHash = strtoupper(sha1($password));

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    app(PwnedPasswordsClient::class)->breachCount($password);

    Http::assertSent(function (HttpRequest $request) use ($fullHash): bool {
        return ! str_contains($request->url(), $fullHash);
    });
});

it('returns the breach count when the suffix matches a row', function (): void {
    $password = 'password';
    $fullHash = strtoupper(sha1($password));
    $suffix = substr($fullHash, 5);

    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response(
            "ABCDEFABCDEFABCDEFABCDEFABCDEFABCDE:7\r\n".
            $suffix.":12345\r\n".
            'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF:1',
            200,
        ),
    ]);

    $count = app(PwnedPasswordsClient::class)->breachCount($password);

    expect($count)->toBe(12345);
});

it('returns 0 when no row matches our suffix', function (): void {
    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response('ABCDEFABCDEFABCDEFABCDEFABCDEFABCDE:7', 200),
    ]);

    expect(app(PwnedPasswordsClient::class)->breachCount('any-string'))->toBe(0);
});

it('matches the suffix case-insensitively (HIBP returns upper-case but stays defensive)', function (): void {
    $password = 'random-passphrase-1';
    $suffix = substr(strtoupper(sha1($password)), 5);

    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response(
            strtolower($suffix).":42\r\n",
            200,
        ),
    ]);

    expect(app(PwnedPasswordsClient::class)->breachCount($password))->toBe(42);
});

it('treats HIBP padding rows (count=0) as unmatched', function (): void {
    $password = 'random-passphrase-2';
    $suffix = substr(strtoupper(sha1($password)), 5);

    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response($suffix.':0', 200),
    ]);

    expect(app(PwnedPasswordsClient::class)->breachCount($password))->toBe(0);
});

it('fails open with breach count 0 on HTTP 5xx', function (): void {
    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response('boom', 503),
    ]);

    expect(app(PwnedPasswordsClient::class)->breachCount('whatever'))->toBe(0);
});

it('fails open with breach count 0 on connection exception', function (): void {
    Http::fake([
        'api.pwnedpasswords.com/range/*' => fn () => throw new ConnectionException('timeout'),
    ]);

    expect(app(PwnedPasswordsClient::class)->breachCount('whatever'))->toBe(0);
});

it('skips malformed rows in the response body', function (): void {
    $password = 'random-passphrase-3';
    $suffix = substr(strtoupper(sha1($password)), 5);

    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response(
            "this-line-has-no-colon\r\n".
            "\r\n".
            $suffix.":99\r\n",
            200,
        ),
    ]);

    expect(app(PwnedPasswordsClient::class)->breachCount($password))->toBe(99);
});

it('sends a User-Agent header per HIBP requirements', function (): void {
    Http::fake(['*' => Http::response('', 200)]);

    app(PwnedPasswordsClient::class)->breachCount('whatever');

    Http::assertSent(function (HttpRequest $request): bool {
        return $request->hasHeader('User-Agent')
            && $request->hasHeader('Add-Padding', 'true');
    });
});
