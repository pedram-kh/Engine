/**
 * Deep-merge for i18n locale bundles.
 *
 * Each module owns one JSON file per locale, but Sprint 13 modules share
 * the `admin.*` top-level namespace (`admin.creators.*`,
 * `admin.agencies.*`, `admin.audit.*`, …). A shallow object spread would
 * make the last-imported `admin` block CLOBBER the earlier ones, silently
 * dropping every other module's strings. This recursive merge preserves
 * all of them by merging plain-object subtrees rather than overwriting.
 *
 * Only plain objects recurse; leaf values (strings) from later sources
 * win — which never happens in practice because each module owns disjoint
 * dotted paths under `admin.*`.
 */

type Json = Record<string, unknown>

function isPlainObject(value: unknown): value is Json {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/**
 * Merge `sources` left-to-right into a fresh object, recursing into
 * nested plain objects. Inputs are not mutated.
 */
export function deepMergeLocale(...sources: Json[]): Json {
  const result: Json = {}

  for (const source of sources) {
    for (const key of Object.keys(source)) {
      const incoming = source[key]
      const existing = result[key]
      if (isPlainObject(existing) && isPlainObject(incoming)) {
        result[key] = deepMergeLocale(existing, incoming)
      } else {
        result[key] = incoming
      }
    }
  }

  return result
}
