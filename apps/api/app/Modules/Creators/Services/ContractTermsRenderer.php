<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use Illuminate\Support\Facades\Cache;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use RuntimeException;

/**
 * Server-side renderer for the master creator agreement.
 *
 * Sprint 3 Chunk 3 sub-step 4. Verified during read pass that
 * `MockEsignProvider` only renders the mock-vendor's button form
 * (apps/api/resources/views/mock-vendor/esign.blade.php) — there is
 * NO pre-existing server-side rendering of the contract markdown
 * itself. This service is the canonical source the click-through
 * fallback (and any future vendor that asks for HTML) consumes.
 *
 * Source-of-truth: the markdown file at
 * `resources/contracts/master-agreement.{locale}.md`. Today we ship
 * `en` only; `pt` + `it` translations land when the legal review
 * for those locales completes (tracked in tech-debt).
 *
 * Sanitisation strategy:
 *   - The markdown is internal content (we author it, not the user)
 *     and rendered with `unsafe_links: false` + the GFM converter's
 *     default HTML-escape behaviour. We treat it as "trusted but
 *     defensively rendered": even though we control the source, we
 *     still pin the converter to a known-safe configuration so a
 *     future translator can't accidentally inject raw HTML.
 *   - The output is cached per-locale per-version for the lifetime
 *     of the process via a small in-process memoisation cache; the
 *     master agreement is small enough that re-rendering on every
 *     request is fine, but the cache avoids paying the
 *     league/commonmark setup cost repeatedly under load.
 */
final class ContractTermsRenderer
{
    public const CURRENT_VERSION = '1.0';

    private const RESOURCES_PATH = 'contracts';

    /**
     * Render the master agreement to safe HTML.
     *
     * @param  string  $locale  e.g. `en`, `pt`, `it`. Defaults to `en`.
     * @return array{html: string, version: string, locale: string}
     *
     * @throws RuntimeException when no markdown source exists for the
     *                          requested locale and `en` fallback is
     *                          unavailable (filesystem / install bug).
     */
    public function render(string $locale = 'en'): array
    {
        $resolvedLocale = $this->resolveLocale($locale);
        $cacheKey = sprintf('contract_terms:%s:%s', self::CURRENT_VERSION, $resolvedLocale);

        return Cache::store('array')->rememberForever($cacheKey, function () use ($resolvedLocale) {
            $source = $this->loadMarkdown($resolvedLocale);
            $html = $this->renderHtml($source);

            return [
                'html' => $html,
                'version' => self::CURRENT_VERSION,
                'locale' => $resolvedLocale,
            ];
        });
    }

    /**
     * Resolves the locale to one we have a source file for. Falls
     * back to `en` if the requested locale has no source — for
     * legal content we'd rather show English than nothing, and the
     * SPA still surfaces the locale-mismatch in the metadata.
     */
    private function resolveLocale(string $requested): string
    {
        $path = $this->sourcePath($requested);
        if (is_string($path) && is_file($path)) {
            return $requested;
        }

        $fallback = $this->sourcePath('en');
        if (! is_string($fallback) || ! is_file($fallback)) {
            throw new RuntimeException('Master agreement source is missing for any locale.');
        }

        return 'en';
    }

    private function sourcePath(string $locale): ?string
    {
        if (preg_match('/^[a-z]{2}$/', $locale) !== 1) {
            return null;
        }

        return resource_path(sprintf('%s/master-agreement.%s.md', self::RESOURCES_PATH, $locale));
    }

    private function loadMarkdown(string $locale): string
    {
        $path = $this->sourcePath($locale);
        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException("Markdown source not found for locale {$locale}.");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Failed to read markdown source for locale {$locale}.");
        }

        return $contents;
    }

    private function renderHtml(string $markdown): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'renderer' => [
                'soft_break' => "\n",
            ],
        ]);

        return (string) $converter->convert($markdown);
    }
}
