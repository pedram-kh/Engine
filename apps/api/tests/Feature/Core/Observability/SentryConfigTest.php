<?php

declare(strict_types=1);

use App\Core\Observability\SentryScrubber;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;
use Tests\Support\SentryRecording;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Sentry configuration, wiring and the empty-DSN off switch (AH-121 C1)
|--------------------------------------------------------------------------
|
| docs/reviews/sentry-plan.md B1 and B7. No real DSN anywhere: the DSN used
| below is a syntactically valid fake, and every request goes to a RECORDING
| HTTP client, never the network.
*/

afterEach(function (): void {
    // Leave no recording hub behind for other tests.
    SentryRecording::reset();
});

it('config/sentry.php sets the privacy and errors-only options', function (): void {
    $c = require base_path('config/sentry.php');

    expect($c['send_default_pii'])->toBeFalse()
        ->and($c['traces_sample_rate'])->toBeNull()
        ->and($c['profiles_sample_rate'])->toBeNull()
        ->and($c['enable_logs'])->toBeFalse()
        ->and($c['enable_metrics'])->toBeFalse()
        ->and($c['max_request_body_size'])->toBe('never')
        ->and($c['attach_stacktrace'])->toBeTrue()
        ->and($c['context_lines'])->toBeNull();

    foreach ($c['breadcrumbs'] as $name => $enabled) {
        expect($enabled)->toBeFalse("breadcrumb source {$name} must be off");
    }
    foreach ($c['tracing'] as $name => $enabled) {
        expect($enabled)->toBeFalse("tracing option {$name} must be off");
    }
    expect($c['crons']['monitor_scheduled_tasks'])->toBeFalse();
});

it('config/sentry.php reads only SENTRY_LARAVEL_DSN, with no fallback to a literal or to SENTRY_DSN', function (): void {
    $source = (string) file_get_contents(base_path('config/sentry.php'));

    expect($source)->toContain("'dsn' => env('SENTRY_LARAVEL_DSN'),")
        ->and($source)->not->toMatch('/env\(\s*[\'"]SENTRY_DSN[\'"]/')
        ->and($source)->not->toMatch('/https?:\/\/[^\s\'"]*(sentry|ingest)/i');
});

it('config/sentry.php contains no Closure (config:cache cannot serialise one)', function (): void {
    $tokens = token_get_all((string) file_get_contents(base_path('config/sentry.php')));
    $forbidden = [T_FUNCTION, T_FN, T_NEW];

    foreach ($tokens as $token) {
        if (is_array($token)) {
            expect(in_array($token[0], $forbidden, true))->toBeFalse('token '.token_name($token[0]).' on line '.$token[2]);
        }
    }

    $config = require base_path('config/sentry.php');
    array_walk_recursive($config, function ($value): void {
        expect(is_object($value))->toBeFalse();
    });
});

it('before_send and before_breadcrumb are static callables that exist', function (): void {
    $c = require base_path('config/sentry.php');

    foreach (['before_send', 'before_breadcrumb'] as $key) {
        expect($c[$key])->toBeArray()->toHaveCount(2);
        [$class, $method] = $c[$key];

        expect(is_string($class))->toBeTrue()
            ->and((new ReflectionMethod($class, $method))->isStatic())->toBeTrue()
            ->and(is_callable($c[$key]))->toBeTrue();
    }
});

it('has no sentry log channel in config/logging.php and none in the default or stack channel', function (): void {
    // The package registers its own `sentry` / `sentry_logs` drivers when its
    // provider boots; they must stay unused, so Log::error lines stay local.
    expect((string) file_get_contents(base_path('config/logging.php')))->not->toMatch('/sentry/i');

    expect(stripos((string) config('logging.default'), 'sentry'))->toBeFalse();

    foreach ((array) config('logging.channels.stack.channels') as $channel) {
        expect(stripos((string) $channel, 'sentry'))->toBeFalse("stack channel {$channel}");
    }
});

it('php artisan config:cache serialises with the Sentry config present', function (): void {
    $cache = sys_get_temp_dir().'/sentry-config-cache-'.bin2hex(random_bytes(4)).'.php';

    try {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'config:cache', '--no-ansi'],
            base_path(),
            ['APP_CONFIG_CACHE' => $cache, 'APP_ENV' => 'testing'],
        );
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and(is_file($cache))->toBeTrue();

        $cached = require $cache;

        expect($cached['sentry']['before_send'])->toBe([SentryScrubber::class, 'beforeSend'])
            ->and($cached['sentry']['send_default_pii'])->toBeFalse();
    } finally {
        @unlink($cache);
    }
});

it('an empty DSN sends zero envelopes and the client reports no DSN', function (): void {
    $http = SentryRecording::install('');
    $hub = SentryRecording::hub();

    $hub->captureException(new RuntimeException('boom'));
    $hub->getClient()?->flush();

    expect($hub->getClient()?->getOptions()->getDsn())->toBeNull()
        ->and($http->bodies)->toBe([]);
});

it('a null DSN sends zero envelopes', function (): void {
    $http = SentryRecording::install(null);
    $hub = SentryRecording::hub();

    $hub->captureException(new RuntimeException('boom'));
    $hub->getClient()?->flush();

    expect($hub->getClient()?->getOptions()->getDsn())->toBeNull()
        ->and($http->bodies)->toBe([]);
});

it('with a (fake) DSN the recording client receives one envelope and nothing private is in it', function (): void {
    $http = SentryRecording::install();
    $recording = $http;
    $hub = SentryRecording::hub();

    $hub->configureScope(function ($scope): void {
        $scope->setUser(['id' => '01JABCDEFGHJKMNPQRSTVWXYZ0', 'email' => 'marisol@example.com', 'username' => 'marisol', 'ip_address' => '203.0.113.9']);
        $scope->setTag('user_type', 'creator');
    });

    $hub->captureException(new RuntimeException('cannot notify marisol@example.com with token=abc123def456'));
    $hub->getClient()?->flush();

    expect($http->bodies)->toHaveCount(1);

    $body = $http->bodies[0];

    // The user is whoever the guards say (nobody here): a user planted on the scope is replaced.
    expect($recording->event()['user'])->toBeNull();
    expect($body)->not->toContain('01JABCDEFGHJKMNPQRSTVWXYZ0');
    expect($body)->toContain('user_type');
    expect($body)->not->toContain('marisol@example.com');
    expect($body)->not->toContain('marisol');
    expect($body)->not->toContain('203.0.113.9');
    expect($body)->not->toContain('abc123def456');
});

it('the ignore list drops ignorable exceptions and every HttpException below 500', function (): void {
    $http = SentryRecording::install();
    $hub = SentryRecording::hub();

    $ignored = [
        ValidationException::withMessages(['a' => 'b']),
        new AuthenticationException,
        new ModelNotFoundException,
        new NotFoundHttpException,
        new HttpException(401),
        new HttpException(403),
        new HttpException(404),
        new HttpException(405),
        new HttpException(409),
        new HttpException(422),
        new HttpException(429),
    ];

    foreach ($ignored as $exception) {
        $hub->captureException($exception);
    }
    $hub->getClient()?->flush();

    expect($http->bodies)->toBe([]);

    $hub->captureException(new HttpException(503));
    $hub->captureException(new RuntimeException('real bug'));
    $hub->getClient()?->flush();

    expect($http->bodies)->toHaveCount(2);
});

it('the DSN comes from SENTRY_LARAVEL_DSN alone: empty means empty, and the generic SENTRY_DSN is ignored', function (): void {
    $show = function (array $env): string {
        $process = new Process([PHP_BINARY, 'artisan', 'config:show', 'sentry.dsn', '--no-ansi'], base_path(), $env + ['APP_ENV' => 'testing']);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        return $process->getOutput();
    };

    expect($show(['SENTRY_LARAVEL_DSN' => '']))->not->toContain('http');
    expect($show(['SENTRY_LARAVEL_DSN' => '', 'SENTRY_DSN' => 'https://k@h.invalid/9']))->not->toContain('h.invalid');
    // Positive control: the variable is the one that is read.
    expect($show(['SENTRY_LARAVEL_DSN' => 'https://k@h.invalid/1']))->toContain('https://k@h.invalid/1');
});

it('keeps Sentry\'s own ids valid (32-hex event and trace ids, 16-hex span id) while a planted 40-character token is redacted', function (): void {
    $sentry = SentryRecording::install();
    $planted = 'Zk3Jq9Wm2Lp8Xc5Vb7Nn4Hg6Fd1Sa0Qw3Er5Ty7U'; // 40 characters

    expect(strlen($planted))->toBe(40);

    SentryRecording::hub()->configureScope(function ($scope) use ($planted): void {
        $scope->setExtra('planted', $planted);
        $scope->setTag('planted_tag', $planted);
    });
    SentryRecording::hub()->captureException(new RuntimeException('boom'));
    $sentry->flush();

    $lines = explode("\n", $sentry->bodies[0]);
    $header = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
    $event = json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR);

    expect($header['event_id'])->toMatch('/^[0-9a-f]{32}$/');
    expect($event['contexts']['trace']['trace_id'])->toMatch('/^[0-9a-f]{32}$/');
    expect($event['contexts']['trace']['span_id'])->toMatch('/^[0-9a-f]{16}$/');
    expect($sentry->bodies[0])->not->toContain($planted);
});
