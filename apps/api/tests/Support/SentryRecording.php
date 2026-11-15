<?php

declare(strict_types=1);

namespace Tests\Support;

use Sentry\ClientBuilder;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Request;
use Sentry\HttpClient\Response;
use Sentry\Laravel\ServiceProvider;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;

/**
 * AH-121: a Sentry hub built from the app's own config/sentry.php whose HTTP
 * client RECORDS every request instead of sending it. No real DSN is ever used.
 */
final class SentryRecording implements HttpClientInterface
{
    public const FAKE_DSN = 'https://publickey@o0.ingest.example.invalid/1';

    /** @var list<string> every envelope body the transport would have sent */
    public array $bodies = [];

    public function sendRequest(Request $request, Options $options): Response
    {
        $this->bodies[] = (string) $request->getStringBody();

        return new Response(200, [], '');
    }

    /**
     * Install a recording hub as the SDK's current hub. Call
     * {@see self::reset()} afterwards (afterEach).
     */
    public static function install(?string $dsn = self::FAKE_DSN): self
    {
        // Package discovery registers this in production; register explicitly so
        // a test does not depend on a stale bootstrap/cache/packages.php.
        app()->register(ServiceProvider::class);
        config()->set('sentry.dsn', $dsn);

        $recording = new self;

        $builder = app()->make(ClientBuilder::class);
        $builder->setHttpClient($recording);

        SentrySdk::setCurrentHub(new Hub($builder->getClient()));

        return $recording;
    }

    public static function hub(): HubInterface
    {
        return SentrySdk::getCurrentHub();
    }

    public function flush(): void
    {
        self::hub()->getClient()?->flush();
    }

    public static function reset(): void
    {
        SentrySdk::setCurrentHub(new Hub);
    }

    /**
     * The whole decoded event body of the n-th recorded event.
     *
     * @return array<string, mixed>
     */
    public function raw(int $index = 0): array
    {
        $lines = explode("\n", $this->bodies[$index]);

        /** @var array<string, mixed> */
        return json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The `user` object of the n-th recorded event (null when the key is absent
     * or every field of it is null), and its tags.
     *
     * @return array{user: mixed, tags: array<mixed>, exception: array<mixed>}
     */
    public function event(int $index = 0): array
    {
        $lines = explode("\n", $this->bodies[$index]);
        /** @var array<string, mixed> $event */
        $event = json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR);

        $user = $event['user'] ?? null;
        if (is_array($user)) {
            $user = array_filter($user, static fn ($v): bool => $v !== null);
            $user = $user === [] ? null : $user;
        }

        return [
            'user' => $user,
            'tags' => (array) ($event['tags'] ?? []),
            'exception' => (array) ($event['exception']['values'] ?? []),
        ];
    }
}
