/**
 * PL-2.4 — a market is a row on the server and travels as its ULID. The strategy screens (address `?market=SK`, tabs,
 * test hooks) still work with the market's short code, which is unique within a wave; this module is the single place that
 * turns one into the other, so no component touches a ULID. Stage 2 of PL-2.4 replaces the code in the screens with the
 * market's name and drops this bridge.
 *
 * The map is filled from the strategy summary (every market of the wave: ulid and code) and is kept per wave.
 */

interface Pair {
  codeById: Map<string, string>
  idByCode: Map<string, string>
}

const registry = new Map<string, Pair>()

const MARKET_KEYS = new Set(['market', 'from_market', 'to_market'])

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** Remember the markets of a wave: `{ market: ulid, code }` rows as the strategy summary lists them. */
export function registerMarkets(waveId: string, rows: { market: string; code: string }[]): void {
  const pair: Pair = { codeById: new Map(), idByCode: new Map() }
  for (const row of rows) {
    pair.codeById.set(row.market, row.code)
    pair.idByCode.set(row.code, row.market)
  }
  registry.set(waveId, pair)
}

/** Pick the markets out of a summary response, if this is one. */
function learn(waveId: string, value: unknown): void {
  if (!isRecord(value) || !isRecord(value['data'])) return
  const attrs = value['data']['attributes']
  if (!isRecord(attrs) || !Array.isArray(attrs['markets'])) return
  const rows = attrs['markets'].flatMap((m: unknown) =>
    isRecord(m) && typeof m['market'] === 'string' && typeof m['code'] === 'string'
      ? [{ market: m['market'], code: m['code'] }]
      : [],
  )
  if (rows.length > 0) registerMarkets(waveId, rows)
}

/** Every market field in a request body: short code to ULID. A value that is not a known code is left as it is. */
export function toWire<T>(waveId: string, value: T): T {
  const pair = registry.get(waveId)
  if (pair === undefined) return value
  const walk = (v: unknown): unknown => {
    if (Array.isArray(v)) return v.map(walk)
    if (!isRecord(v)) return v
    return Object.fromEntries(
      Object.entries(v).map(([k, x]) => [
        k,
        MARKET_KEYS.has(k) && typeof x === 'string' ? (pair.idByCode.get(x) ?? x) : walk(x),
      ]),
    )
  }
  return walk(value) as T
}

/** `?market=SK` in a URL: short code to ULID. */
export function urlToWire(waveId: string, url: string): string {
  const pair = registry.get(waveId)
  if (pair === undefined) return url
  return url.replace(/([?&]market=)([^&]+)/, (all, head: string, raw: string) => {
    const id = pair.idByCode.get(decodeURIComponent(raw))
    return id === undefined ? all : `${head}${encodeURIComponent(id)}`
  })
}

/** Every market field in a response: ULID to short code (after learning the markets from a summary). */
export function fromWire<T>(waveId: string, value: T): T {
  learn(waveId, value)
  const pair = registry.get(waveId)
  if (pair === undefined) return value
  const walk = (v: unknown): unknown => {
    if (Array.isArray(v)) return v.map(walk)
    if (!isRecord(v)) return v
    return Object.fromEntries(
      Object.entries(v).map(([k, x]) => [
        k,
        MARKET_KEYS.has(k) && typeof x === 'string' ? (pair.codeById.get(x) ?? x) : walk(x),
      ]),
    )
  }
  return walk(value) as T
}
