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
    public const CURRENT_VERSION = '1.1';

    /**
     * Fallback title when the markdown source has no leading `# ` heading.
     */
    private const DEFAULT_TITLE = 'Master Creator Agreement';

    private const RESOURCES_PATH = 'contracts';

    /**
     * Map a human-facing semantic version string (e.g. `'1.0'`) to the
     * integer the `contracts.version` column stores (docs/03-DATA-MODEL.md
     * §8, `:583`). The precise string is preserved separately in
     * `signed_signature_data.version`, so this lossy major-version mapping
     * is for the queryable integer column only.
     *
     * Centralised here — the single owner of {@see self::CURRENT_VERSION} —
     * so a future `'2.0'` bump doesn't have to hunt down a parallel constant.
     */
    public static function versionToInteger(string $version): int
    {
        return (int) $version;
    }

    /**
     * The integer form of the version currently in force.
     */
    public static function currentVersionNumber(): int
    {
        return self::versionToInteger(self::CURRENT_VERSION);
    }

    /**
     * The raw (un-rendered) markdown source + its title and version, for
     * callers that need to SNAPSHOT what was agreed — the click-through
     * accept persists `contracts.body_markdown` / `title` from this
     * (Sprint 4 Chunk 4, D-c4-2).
     *
     * Deliberately a SEPARATE method from {@see self::render()}: exposing
     * the raw source must not perturb the rendered-HTML output the SPA
     * consumes (its sanitisation contract is pinned by
     * ContractTermsEndpointTest). This method shares the locale-resolution
     * and file-loading helpers but renders nothing.
     *
     * @return array{markdown: string, title: string, version: string, locale: string}
     *
     * @throws RuntimeException when no markdown source exists (see render()).
     */
    public function source(string $locale = 'en'): array
    {
        $resolvedLocale = $this->resolveLocale($locale);
        $markdown = $this->loadMarkdown($resolvedLocale);

        return [
            'markdown' => $markdown,
            'title' => $this->extractTitle($markdown),
            'version' => self::CURRENT_VERSION,
            'locale' => $resolvedLocale,
        ];
    }

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

    /**
     * Pull the document title from the markdown's first `# ` heading,
     * falling back to a constant. Used to populate `contracts.title` at
     * accept time — purely a read over the loaded source, no rendering.
     */
    private function extractTitle(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '# ')) {
                return mb_substr(trim($line), 2);
            }
        }

        return self::DEFAULT_TITLE;
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
