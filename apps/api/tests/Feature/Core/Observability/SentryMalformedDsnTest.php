<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| A malformed SENTRY_LARAVEL_DSN must not take the app down (AH-121 C4)
|--------------------------------------------------------------------------
|
| A typo or a wrong paste in the box .env is the likeliest way Sentry goes
| wrong in production. Each case boots a FRESH app in a child process (the
| way php-fpm and the workers boot it) with the malformed value in the
| environment, because the service provider decides at boot whether Sentry is
| on. Nothing is sent: the value has no usable host, and the control case
| below proves the child really does see a DSN when the value is well-formed.
*/

const MALFORMED_DSNS = ['not-a-dsn', 'https://example.invalid/1', 'ftp://key@host/1'];

/**
 * @return array{0: int, 1: string} exit code and combined output
 */
function bootChild(string $dsn, array $arguments): array
{
    $process = new Process([PHP_BINARY, base_path('artisan'), ...$arguments], base_path(), [
        'SENTRY_LARAVEL_DSN' => $dsn,
        'APP_ENV' => 'testing',
    ]);
    $process->setTimeout(60);
    $process->run();

    return [$process->getExitCode() ?? 1, $process->getOutput().$process->getErrorOutput()];
}

function tinkerScript(string $php): array
{
    return ['tinker', '--execute='.$php];
}

const REQUESTS_SCRIPT = <<<'PHP'
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
Route::get('/_dsn-boom', fn () => throw new RuntimeException('route exploded'));
$k = app(Illuminate\Contracts\Http\Kernel::class);
$up = $k->handle(Request::create('/up'));
echo 'up='.$up->getStatusCode().PHP_EOL;
$boom = $k->handle(Request::create('/_dsn-boom', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']));
echo 'boom='.$boom->getStatusCode().PHP_EOL;
echo 'boomjson='.(json_decode($boom->getContent()) !== null ? 'yes' : 'no').PHP_EOL;
PHP;

it('control: a well-formed DSN is seen by the child, so the malformed cases are not vacuous', function (): void {
    [$code, $out] = bootChild('https://publickey@o0.ingest.example.invalid/1', tinkerScript('echo "dsn=".(app("sentry")->getClient()?->getOptions()->getDsn() === null ? "none" : "set").PHP_EOL;'));

    expect($code)->toBe(0);
    expect($out)->toContain('dsn=set');
});

it('a malformed DSN is no DSN at all: the SDK has none and sends nothing', function (string $dsn): void {
    [$code, $out] = bootChild($dsn, tinkerScript('echo "dsn=".(app("sentry")->getClient()?->getOptions()->getDsn() === null ? "none" : "set").PHP_EOL;'));

    expect($code)->toBe(0);
    expect($out)->toContain('dsn=none');
})->with(MALFORMED_DSNS);

it('(a) a normal request still returns its normal response', function (string $dsn): void {
    [$code, $out] = bootChild($dsn, tinkerScript(REQUESTS_SCRIPT));

    expect($code)->toBe(0);
    expect($out)->toContain('up=200');
})->with(MALFORMED_DSNS);

it('(b) an exception in a route still returns the normal error response', function (string $dsn): void {
    [$code, $out] = bootChild($dsn, tinkerScript(REQUESTS_SCRIPT));

    expect($code)->toBe(0);
    expect($out)->toContain('boom=500');
    expect($out)->toContain('boomjson=yes');
    expect($out)->not->toContain('InvalidArgumentException');
})->with(MALFORMED_DSNS);

it('(c) artisan commands still run', function (string $dsn): void {
    [$code, $out] = bootChild($dsn, ['inspire']);

    expect($code)->toBe(0);
    expect($out)->not->toContain('InvalidArgumentException');
    expect(trim($out))->not->toBe('');
})->with(MALFORMED_DSNS);

it('reporting an exception under a malformed DSN does not throw', function (string $dsn): void {
    [$code, $out] = bootChild($dsn, tinkerScript('report(new Exception("x")); echo "reported".PHP_EOL;'));

    expect($code)->toBe(0);
    expect($out)->toContain('reported');
})->with(MALFORMED_DSNS);
