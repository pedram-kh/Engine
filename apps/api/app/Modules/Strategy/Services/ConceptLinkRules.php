<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Strategy\StrategyLimits;

/**
 * The URL rules of a concept reference link (plan section 1.6, rulings C4 and Q7). Pure functions: nothing here makes a
 * request to the URL (the server never fetches a pasted URL). `https` only; a host with a dot; no user-info; no IP
 * literal; no `localhost`; no whitespace or control character; at most 2048 characters.
 */
final class ConceptLinkRules
{
    /** @throws StrategyRuleViolation `link.invalid_url` */
    public static function validate(string $raw): string
    {
        $url = trim($raw);
        if ($url === '') {
            throw StrategyRuleViolation::linkInvalidUrl('Paste a link.');
        }
        if (mb_strlen($url) > StrategyLimits::LINK_URL) {
            throw StrategyRuleViolation::linkInvalidUrl('A link has at most '.StrategyLimits::LINK_URL.' characters.');
        }
        if (preg_match('/[\s\x00-\x1F\x7F]/u', $url) === 1) {
            throw StrategyRuleViolation::linkInvalidUrl('A link has no spaces or control characters.');
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw StrategyRuleViolation::linkInvalidUrl('This is not a valid link.');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            throw StrategyRuleViolation::linkInvalidUrl('Links must start with https://');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw StrategyRuleViolation::linkInvalidUrl('A link cannot carry a user name or password.');
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw StrategyRuleViolation::linkInvalidUrl('A link cannot point at this computer.');
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            throw StrategyRuleViolation::linkInvalidUrl('A link needs a website name, not an IP address.');
        }
        if (! str_contains($host, '.')) {
            throw StrategyRuleViolation::linkInvalidUrl('A link needs a full website name (for example example.com).');
        }

        return $url;
    }

    /** The host of a stored URL, lower-cased, without a leading `www.`; shown as stored (an internationalised host stays in its ASCII form). */
    public static function domain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host)) {
            return '';
        }
        $host = strtolower(rtrim($host, '.'));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** Only an `https` URL may become an href. */
    public static function isSafeHref(string $url): bool
    {
        return preg_match('#^https://#i', $url) === 1;
    }
}
