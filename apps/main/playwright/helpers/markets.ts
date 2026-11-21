/**
 * PL-2.4 — a wave is created with markets as objects. The specs think in country codes, so this turns `['SK', 'RO']` into
 * one single-country market each (named for the country, the country code as short code), as the web's "add a country" does.
 */
const NAMES: Record<string, string> = {
  SK: 'Slovakia',
  RO: 'Romania',
  CZ: 'Czechia',
  IE: 'Ireland',
  DE: 'Germany',
  FR: 'France',
}

export function wireMarkets(
  codes: string[],
): { name: string; code: string; countries: string[] }[] {
  return codes.map((code) => ({ name: NAMES[code] ?? code, code, countries: [code] }))
}

/** Short code to market ulid, read from the wave the spec just made (`GET .../waves/{wave}`), for specs that call the strategy API directly. */
export async function marketIds(
  get: (path: string) => Promise<unknown>,
  wavePath: string,
): Promise<Record<string, string>> {
  const res = (await get(wavePath)) as {
    data: { attributes: { markets: { id: string; code: string }[] } }
  }
  return Object.fromEntries(res.data.attributes.markets.map((m) => [m.code, m.id]))
}
