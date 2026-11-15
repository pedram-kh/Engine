<?php

declare(strict_types=1);

use App\Core\Observability\SentryScrubber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/*
|--------------------------------------------------------------------------
| Sentry (AH-121, docs/reviews/sentry-plan.md B1)
|--------------------------------------------------------------------------
|
| Errors only. Nothing is sent unless SENTRY_LARAVEL_DSN is set: an empty
| DSN gives the SDK no transport. There is deliberately NO fallback to a
| literal DSN and NO fallback to the generic SENTRY_DSN variable.
|
| This file MUST NOT contain a Closure. Production runs `config:cache`,
| which cannot serialise one; callbacks are static callables
| (`[Class::class, 'method']`). Pinned by SentryConfigTest.
|
*/

return [

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    'release' => env('SENTRY_RELEASE'),

    // Empty means "use APP_ENV", and a process-level APP_ENV beats the .env file. Production
    // sets SENTRY_ENVIRONMENT=production explicitly so the tag is never ambiguous.
    'environment' => env('SENTRY_ENVIRONMENT'),

    // Fixed name: the SDK would send the machine hostname (the box, or a developer's Mac).
    'server_name' => 'catalyst-api',

    'sample_rate' => 1.0,

    // Errors only: no tracing, no profiling, no logs, no metrics.
    'traces_sample_rate' => null,
    'profiles_sample_rate' => null,
    'enable_logs' => false,
    'enable_metrics' => false,

    // Never attach the user's IP, cookies or headers by default.
    'send_default_pii' => false,

    // No request body at all; the scrubber is the second wall.
    'max_request_body_size' => 'never',

    // Frames carry file, function and line only: no source excerpts.
    'attach_stacktrace' => true,
    'context_lines' => null,

    // The two walls every event and breadcrumb passes through.
    'before_send' => [SentryScrubber::class, 'beforeSend'],
    'before_breadcrumb' => [SentryScrubber::class, 'beforeBreadcrumb'],

    // Belt: Laravel keeps most of these out of report() already. Every
    // HttpException below 500 is also dropped by SentryScrubber::beforeSend.
    'ignore_exceptions' => [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        NotFoundHttpException::class,
        TokenMismatchException::class,
        TooManyRequestsHttpException::class,
        ThrottleRequestsException::class,
    ],

    'ignore_transactions' => [
        '/up',
    ],

    // Errors = exceptions only. Every breadcrumb source is off.
    'breadcrumbs' => [
        'logs' => false,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'queue_info' => false,
        'command_info' => false,
        'http_client_requests' => false,
        'notifications' => false,
    ],

    // Crons monitoring deferred (plan Q11).
    'crons' => [
        'monitor_scheduled_tasks' => false,
        'exclude_scheduled_tasks' => [],
    ],

    // Tracing is off (traces_sample_rate null); every span source is off too.
    'tracing' => [
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'sql_origin' => false,
        'views' => false,
        'livewire' => false,
        'http_client_requests' => false,
        'http_client_requests_origin' => false,
        'cache' => false,
        'redis_commands' => false,
        'redis_origin' => false,
        'notifications' => false,
        'missing_routes' => false,
        'continue_after_response' => false,
        'gen_ai' => false,
        'gen_ai_invoke_agent' => false,
        'gen_ai_chat' => false,
        'gen_ai_execute_tool' => false,
        'gen_ai_embeddings' => false,
        'gen_ai_evaluate' => false,
        'default_integrations' => false,
    ],

];
