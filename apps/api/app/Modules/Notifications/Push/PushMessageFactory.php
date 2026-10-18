<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use App\Core\Enums\Locale;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;

/**
 * Builds the vendor-neutral {@see PushMessage} for one (recipient, type) — and is
 * where the lock-screen PRIVACY contract (Q6) is enforced structurally.
 *
 * WHAT CAN REACH A PAYLOAD. Only:
 *   - a fixed, localized `title` per type (`lang/{locale}/push.php`);
 *   - an optional localized `body` whose placeholders are drawn ONLY from
 *     {@see self::NAME_KEYS} — campaign / agency / counterparty / sender NAMES;
 *   - `data` = {type, v, notification_ulid?} plus, per type, the ULID route keys
 *     listed in {@see self::ROUTE_KEYS_BY_TYPE} (AH-103 — what the tap opens).
 * The notification's `data` bag is NEVER forwarded: it carries `feedback`,
 * `rejection_reason` and the like (rendered in-app, never on a lock screen), and
 * message events carry text-adjacent fields. The factory reads exactly two sets
 * of named keys out of it — the four NAME_KEYS (placeholders) and, for the
 * type, its ROUTE_KEYS_BY_TYPE entry (ids) — and ignores everything else. A route
 * key is forwarded only when it is listed FOR THAT TYPE and its value is
 * ULID-shaped; there is no code path that copies the bag.
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

    /**
     * AH-103 — the ONLY id keys a push may carry, PER TYPE (the `push` column of
     * `packages/api-client/contracts/notification-route-keys.json`; a parity test
     * keeps the two equal). Per type, not one flat list, so `campaign_ulid` cannot
     * start riding a creator message if a later change adds it to a shared bag.
     * Account-lifecycle and relationship events carry none: their screens need no id.
     *
     * @var array<string, list<string>>
     */
    public const array ROUTE_KEYS_BY_TYPE = [
        'assignment.invited' => ['assignment_ulid'],
        'assignment.revision_requested' => ['assignment_ulid'],
        'assignment.draft_approved' => ['assignment_ulid'],
        'assignment.draft_rejected' => ['assignment_ulid'],
        'assignment.completed_on_approval' => ['assignment_ulid'],
        'assignment.manually_verified' => ['assignment_ulid'],
        'message.received_by_creator' => ['assignment_ulid'],
        'message.relationship_received_by_creator' => ['thread_ulid'],
        'campaign_application.accepted' => ['assignment_ulid'],
        'campaign.job_posted' => ['campaign_ulid'],
        'creator.approved' => [],
        'creator.rejected' => [],
        'agency_creator_relation.admin_connected' => [],
        'agency_creator_relation.disconnected' => [],
    ];

    public const int NAME_MAX_CHARS = 60;

    /** Payload schema version the app can branch on. */
    public const int PAYLOAD_VERSION = 1;

    /** A ULID: 26 Crockford-base32 characters (`HasUlid` stores uppercase; case-insensitive on purpose). */
    private const string ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}\z/i';

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

        foreach (self::routeKeys($type, $data) as $key => $value) {
            $payload[$key] = $value;
        }

        return new PushMessage(
            title: $title,
            body: $this->body($base.'.body', $locale, $data),
            data: $payload,
            collapseKey: self::threadKey($type, $data),
            channelId: PushMessage::DEFAULT_CHANNEL,
            badge: $this->badgeFor($recipient),
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
     * AH-108 (Q5) — the home-screen icon number: the recipient's TOTAL unread
     * (`notifications.read_at IS NULL`), the same number the bell shows. One COUNT
     * per push.
     *
     * Computed at build time, which is AFTER `NotificationService::notify()` has
     * written the in-app row, so the message that triggered this push is already in
     * the total. When the recipient has muted `in_app` there is no row, and that one
     * message is not counted (accepted, tech-debt).
     *
     * `null` for a recipient that is not persisted: there is nothing to count, and a
     * made-up 0 would clear the icon. A persisted recipient with nothing unread gets
     * a real 0, which is correct.
     */
    private function badgeFor(User $recipient): ?int
    {
        if (! $recipient->exists) {
            return null;
        }

        return Notification::unreadCountFor($recipient);
    }

    /**
     * The route ids this type may forward: listed for the type, a string, ULID-shaped.
     * Anything else is dropped silently — the push still sends and the tap falls back.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function routeKeys(NotificationType $type, array $data): array
    {
        $out = [];

        foreach (self::ROUTE_KEYS_BY_TYPE[$type->value] ?? [] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && preg_match(self::ULID_PATTERN, $value) === 1) {
                $out[$key] = $value;
            }
        }

        return $out;
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
