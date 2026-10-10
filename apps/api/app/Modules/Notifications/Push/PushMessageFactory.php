<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use App\Core\Enums\Locale;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;

/**
 * Builds the vendor-neutral {@see PushMessage} for one (recipient, type) — and is
 * where the lock-screen PRIVACY contract (Q6) is enforced structurally.
 *
 * WHAT CAN REACH A PAYLOAD. Only:
 *   - a fixed, localized `title` per type (`lang/{locale}/push.php`);
 *   - an optional localized `body` whose placeholders are drawn ONLY from
 *     {@see self::NAME_KEYS} — campaign / agency / counterparty / sender NAMES;
 *   - `data` = {type, v, notification_ulid?}.
 * The notification's `data` bag is NEVER forwarded: it carries `feedback`,
 * `rejection_reason` and the like (rendered in-app, never on a lock screen), and
 * message events carry text-adjacent fields. The factory reads exactly four
 * named keys out of it and ignores everything else — there is no code path that
 * copies the bag.
 *
 * LOCALE. Rendered with an EXPLICIT locale argument (the recipient's
 * `preferred_language`, clamped to a rendered UI locale, else `en`) — never
 * `App::setLocale()`, because `notify()` can run mid-request/transaction and must
 * not mutate the caller's locale.
 *
 * NAMES. Injected untranslated, control/format characters stripped (incl. bidi
 * overrides), whitespace collapsed, length-capped. If a body needs a name that is
 * absent/empty the body is dropped and the title alone is sent.
 */
final class PushMessageFactory
{
    /** The ONLY `data`-bag keys this factory will read, each as a placeholder name. */
    public const array NAME_KEYS = ['campaign_name', 'agency_name', 'counterparty_name', 'sender_name'];

    public const int NAME_MAX_CHARS = 60;

    /** Payload schema version the app can branch on. */
    public const int PAYLOAD_VERSION = 1;

    /**
     * @param  array<string, mixed>  $data  the in-app notification's `data` bag (read via allowlist ONLY)
     */
    public function make(NotificationType $type, User $recipient, array $data, ?string $notificationUlid = null): ?PushMessage
    {
        if (! PushEligibility::isEligible($type)) {
            return null;
        }

        $locale = $this->localeFor($recipient);
        $base = 'push.'.self::copyKey($type);

        $title = trans($base.'.title', [], $locale);

        if (! is_string($title) || $title === '' || $title === $base.'.title') {
            return null; // No copy for this type: send nothing rather than a raw key.
        }

        $payload = ['type' => $type->value, 'v' => self::PAYLOAD_VERSION];

        if ($notificationUlid !== null && $notificationUlid !== '') {
            $payload['notification_ulid'] = $notificationUlid;
        }

        return new PushMessage(
            title: $title,
            body: $this->body($base.'.body', $locale, $data),
            data: $payload,
            collapseKey: self::threadKey($type, $data),
            channelId: PushMessage::DEFAULT_CHANNEL,
        );
    }

    /**
     * The per-conversation key for the two message types — the SAME value is the
     * dispatcher's throttle key and the provider collapse key, so a burst on one
     * thread collapses on the device and is rate-limited on the server. Null for
     * every other type (their pushes are discrete events and never collapse).
     *
     * @param  array<string, mixed>  $data
     */
    public static function threadKey(NotificationType $type, array $data): ?string
    {
        $key = match ($type) {
            NotificationType::MessageReceivedByCreator => ['assignment', $data['assignment_ulid'] ?? null],
            NotificationType::MessageRelationshipReceivedByCreator => ['thread', $data['thread_ulid'] ?? null],
            default => null,
        };

        if ($key === null || ! is_string($key[1]) || $key[1] === '') {
            return null;
        }

        return $key[0].':'.$key[1];
    }

    /** `agency_creator_relation.disconnected` → `agency_creator_relation_disconnected`. */
    public static function copyKey(NotificationType $type): string
    {
        return str_replace('.', '_', $type->value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function body(string $key, string $locale, array $data): ?string
    {
        // The placeholders the SOURCE (en) string uses decide which names are required.
        $source = trans($key, [], 'en');

        if (! is_string($source) || $source === '' || $source === $key) {
            return null;
        }

        preg_match_all('/:([a-z_]+)/', $source, $matches);

        $replace = [];

        foreach (array_unique($matches[1]) as $name) {
            if (! in_array($name, self::NAME_KEYS, true)) {
                return null; // A body may only ever reference allowlisted names.
            }

            $value = $this->cleanName($data[$name] ?? null);

            if ($value === null) {
                return null; // Required name missing → title alone.
            }

            $replace[$name] = $value;
        }

        $body = trans($key, $replace, $locale);

        return is_string($body) && $body !== '' && $body !== $key ? $body : null;
    }

    private function cleanName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // Strip control (Cc) and format (Cf: zero-width, bidi override) characters, then collapse whitespace.
        $clean = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value);
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $clean));

        if ($clean === '') {
            return null;
        }

        if (mb_strlen($clean) > self::NAME_MAX_CHARS) {
            $clean = rtrim(mb_substr($clean, 0, self::NAME_MAX_CHARS - 1)).'…';
        }

        return $clean;
    }

    private function localeFor(User $recipient): string
    {
        $preferred = $recipient->preferred_language;

        return is_string($preferred) && in_array($preferred, Locale::uiValues(), true) ? $preferred : 'en';
    }
}
