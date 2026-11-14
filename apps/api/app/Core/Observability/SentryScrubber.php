<?php

declare(strict_types=1);

namespace App\Core\Observability;

use Illuminate\Database\QueryException;
use PDOException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The two walls every Sentry event and breadcrumb passes through
 * (docs/reviews/sentry-plan.md B1, AH-121).
 *
 * Wired in config/sentry.php as STATIC callables, never Closures: production
 * runs `config:cache`, which cannot serialise a Closure.
 *
 * Rules (numbers match the plan):
 *  1. emails redacted in every free-text field
 *  2. bearer / Sanctum / key=value secrets / long opaque tokens redacted
 *  3. sensitive headers and cookies deleted
 *  4. query string and fragment stripped from URLs
 *  5. request body, cookies, query string, env dropped
 *  6. user block rebuilt as {id} (a ulid) only, or removed
 *  7. stack frame variables and source context dropped
 *  8. erasure namespace: exception class only, no message
 *  9. QueryException / PDOException: class and SQLSTATE only
 */
final class SentryScrubber
{
    private const REDACTED = '[redacted]';

    private const ERASURE_NAMESPACE = 'App\\Modules\\Erasure\\';

    /** Header names (lower case) that never leave. */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-xsrf-token',
        'x-csrf-token',
        'x-test-helpers-token',
    ];

    /** Metadata / array keys that hold a URL and get query + fragment stripped. */
    private const URL_KEYS = ['url', 'from', 'to', 'referrer', 'referer', 'description', 'uri'];

    /**
     * Sentry's own identifiers: the opaque-token rule must not touch them. Exempt only
     * when the value has exactly the shape of the id (32 or 16 lower-case hex), so a
     * token planted under one of these keys is still redacted.
     */
    private const SENTRY_ID_SHAPES = [
        'event_id' => '/^[0-9a-f]{32}$/',
        'trace_id' => '/^[0-9a-f]{32}$/',
        'span_id' => '/^[0-9a-f]{16}$/',
        'parent_span_id' => '/^[0-9a-f]{16}$/',
    ];

    /** Metadata keys that exist only to carry a query string or fragment. */
    private const QUERY_KEYS = ['http.query', 'http.fragment', 'query', 'query_string', 'fragment'];

    /**
     * Static callable for `before_send`.
     */
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $exception = $hint?->exception;

        // Any HttpException below 500 (401, 403, 404, 405, 409, 422, 429...) never leaves.
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return null;
        }

        self::scrubExceptions($event, $exception);
        self::scrubMessage($event);
        self::scrubRequest($event);
        self::scrubUser($event);

        $event->setTags(self::scrubArray($event->getTags()));
        $event->setExtra(self::scrubArray($event->getExtra()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext((string) $name, self::scrubArray($context));
        }

        $transaction = $event->getTransaction();
        if ($transaction !== null) {
            $event->setTransaction(self::scrubString($transaction));
        }

        $event->setBreadcrumb(self::scrubBreadcrumbs($event->getBreadcrumbs()));

        return $event;
    }

    /**
     * Static callable for `before_breadcrumb`.
     */
    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $message = $breadcrumb->getMessage();
        if ($message !== null) {
            $breadcrumb = $breadcrumb->withMessage(self::scrubString($message));
        }

        foreach ($breadcrumb->getMetadata() as $key => $value) {
            $key = (string) $key;

            if (in_array(strtolower($key), self::QUERY_KEYS, true)) {
                $breadcrumb = $breadcrumb->withoutMetadata($key);

                continue;
            }

            $breadcrumb = $breadcrumb->withMetadata($key, self::scrubValue($key, $value));
        }

        return $breadcrumb;
    }

    /**
     * Strip the query string and fragment from a URL or a URL-shaped string.
     */
    public static function stripUrl(string $url): string
    {
        $cut = strcspn($url, '?#');

        return $cut === strlen($url) ? $url : substr($url, 0, $cut);
    }

    /**
     * Rules 1, 2 and 4 on free text.
     */
    public static function scrubString(string $text): string
    {
        // 4. URLs inside text lose their query and fragment (presigned S3, signed URLs).
        $text = self::replace('~(https?://[^\s?#"\'<>]+)[?#][^\s"\'<>]*~i', '$1', $text);

        // 2. Bearer tokens.
        $text = self::replace('~Bearer\s+[A-Za-z0-9._\~+/=\-]+~i', 'Bearer '.self::REDACTED, $text);

        // 2. Sanctum personal access tokens: "<id>|<40 alnum>".
        $text = self::replace('~\b\d+\|[A-Za-z0-9]{40}\b~', self::REDACTED, $text);

        // 2. key=value secrets (query or form pairs), including prefixed names
        //    such as current_password= or X-Amz-Signature=.
        $text = self::replace(
            '~([A-Za-z0-9_\-]*(?:token|signature|password|secret|credential|code|key))=[^&\s"\'<>]*~i',
            '$1='.self::REDACTED,
            $text,
        );

        // 1. Emails.
        $text = self::replace('~[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}~', '[email]', $text);

        // 2. Opaque tokens the app issues (invitation, verification, reset: 60-64
        //    char random or sha256 hex) and any other long unbroken secret-shaped run.
        return self::replace('~(?<![A-Za-z0-9_\-])[A-Za-z0-9_\-]{32,}(?![A-Za-z0-9_\-])~', '[token]', $text);
    }

    /**
     * preg_replace that fails CLOSED: if a pattern ever errors (backtrack
     * limit, bad UTF-8) the text is replaced, never passed through.
     */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);

        return is_string($result) ? $result : '[unscrubbed]';
    }

    private static function scrubValue(string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            return self::scrubArray($value);
        }

        if (! is_string($value)) {
            return $value;
        }

        $shape = self::SENTRY_ID_SHAPES[strtolower($key)] ?? null;
        if ($shape !== null && preg_match($shape, $value) === 1) {
            return $value;
        }

        if (in_array(strtolower($key), self::URL_KEYS, true)) {
            $value = self::stripUrl($value);
        }

        return self::scrubString($value);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function scrubArray(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $out[$key] = self::scrubValue((string) $key, $value);
        }

        return $out;
    }

    /**
     * @param  array<Breadcrumb>  $breadcrumbs
     * @return array<Breadcrumb>
     */
    private static function scrubBreadcrumbs(array $breadcrumbs): array
    {
        $out = [];

        foreach ($breadcrumbs as $breadcrumb) {
            $out[] = self::beforeBreadcrumb($breadcrumb);
        }

        return $out;
    }

    private static function scrubMessage(Event $event): void
    {
        $message = $event->getMessage();
        if ($message === null) {
            return;
        }

        $formatted = $event->getMessageFormatted();
        $event->setMessage(
            self::scrubString($message),
            self::scrubArray($event->getMessageParams()),
            $formatted === null ? null : self::scrubString($formatted),
        );
    }

    private static function scrubRequest(Event $event): void
    {
        $request = $event->getRequest();
        if ($request === []) {
            return;
        }

        // 5. Body, cookies, query string, env: dropped, never redacted-in-place.
        unset($request['data'], $request['cookies'], $request['query_string'], $request['env'], $request['fragment']);

        // 4. URL without query or fragment.
        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = self::scrubString(self::stripUrl($request['url']));
        }

        // 3. Sensitive headers deleted; the rest scrubbed (referrer loses its query).
        if (isset($request['headers']) && is_array($request['headers'])) {
            $headers = [];
            foreach ($request['headers'] as $name => $value) {
                if (in_array(strtolower((string) $name), self::SENSITIVE_HEADERS, true)) {
                    continue;
                }

                $isUrlHeader = in_array(strtolower((string) $name), ['referer', 'referrer', 'origin'], true);
                $clean = static fn (mixed $v): mixed => is_string($v) ? self::scrubString($isUrlHeader ? self::stripUrl($v) : $v) : $v;
                $value = is_array($value) ? array_map($clean, $value) : $clean($value);

                $headers[$name] = $value;
            }
            $request['headers'] = $headers;
        }

        $event->setRequest($request);
    }

    /**
     * Rule 6: the user block is {id} only, and only when the id is a ulid.
     * Anything else (an install id, a numeric id, an email in `id`) removes it.
     */
    private static function scrubUser(Event $event): void
    {
        $user = $event->getUser();
        if ($user === null) {
            return;
        }

        $id = $user->getId();

        if (is_string($id) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id) === 1) {
            $event->setUser(UserDataBag::createFromUserIdentifier($id));

            return;
        }

        $event->setUser(null);
    }

    private static function scrubExceptions(Event $event, ?\Throwable $thrown): void
    {
        $bags = $event->getExceptions();
        if ($bags === []) {
            return;
        }

        $erasure = false;
        foreach ($bags as $bag) {
            if (self::touchesErasure($bag)) {
                $erasure = true;
            }
        }

        foreach ($bags as $bag) {
            // 7. Frame variables and source context dropped.
            self::stripFrames($bag);

            if (self::isDatabaseException($bag->getType())) {
                // 9. Class and SQLSTATE only. Never the SQL, never the bindings.
                $bag->setValue(self::databaseValue($bag, $thrown));

                continue;
            }

            if ($erasure) {
                // 8. Class name only; frames stay.
                $bag->setValue($bag->getType());

                continue;
            }

            $bag->setValue(self::scrubString($bag->getValue()));
        }

        $event->setExceptions($bags);
    }

    private static function isDatabaseException(string $type): bool
    {
        return is_a($type, QueryException::class, true)
            || is_a($type, PDOException::class, true);
    }

    private static function databaseValue(ExceptionDataBag $bag, ?\Throwable $thrown): string
    {
        $short = ($pos = strrpos($bag->getType(), '\\')) === false ? $bag->getType() : substr($bag->getType(), $pos + 1);

        $state = null;
        if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $bag->getValue(), $m) === 1) {
            $state = $m[1];
        }

        for ($e = $thrown; $state === null && $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof PDOException && isset($e->errorInfo[0]) && is_string($e->errorInfo[0])
                && preg_match('/^[0-9A-Z]{5}$/', $e->errorInfo[0]) === 1) {
                $state = $e->errorInfo[0];
            } elseif (is_string($e->getCode()) && preg_match('/^[0-9A-Z]{5}$/', $e->getCode()) === 1) {
                $state = $e->getCode();
            }
        }

        return $short.' (SQLSTATE '.($state ?? 'unknown').')';
    }

    private static function touchesErasure(ExceptionDataBag $bag): bool
    {
        if (str_starts_with($bag->getType(), self::ERASURE_NAMESPACE)) {
            return true;
        }

        $stacktrace = $bag->getStacktrace();
        if ($stacktrace === null) {
            return false;
        }

        foreach ($stacktrace->getFrames() as $frame) {
            $function = (string) ($frame->getRawFunctionName() ?? $frame->getFunctionName());
            $path = str_replace('\\', '/', (string) $frame->getAbsoluteFilePath());

            if (str_starts_with($function, self::ERASURE_NAMESPACE)
                || str_contains($path, '/app/Modules/Erasure/')) {
                return true;
            }
        }

        return false;
    }

    private static function stripFrames(ExceptionDataBag $bag): void
    {
        $stacktrace = $bag->getStacktrace();
        if ($stacktrace === null) {
            return;
        }

        foreach ($stacktrace->getFrames() as $frame) {
            $frame->setVars([]);
            $frame->setPreContext([]);
            $frame->setContextLine(null);
            $frame->setPostContext([]);
        }
    }
}
