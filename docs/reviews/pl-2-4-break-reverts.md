# PL-2.4 Stage 1: break-revert record

Method, for every gate: patch the code (or the migration the gate protects), run the targeted tests and require them
**red**, restore the file from memory in a `finally`, re-run the same tests and require them **green**. `git status` after
the run showed no source change besides the tests added on purpose. Database gates (D1 to D6) ran on SQLite and on
PostgreSQL (throwaway `pl24_test`, port 5435); the rest on SQLite. Driver: the same script for every row.

38 gates, 38 red under the break, 38 green after the revert.

## Gates the first pass found weak (fixed, then re-run red)

| Gate | What the first pass showed                                                                                                                                                        |
| ---- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| B13  | The first pass stayed green: a plain string already fails `array`, so nothing proved the key list. Test W2b added (extra keys refused on create and edit).                        |
| B18  | The first pass stayed green: the wave scope hid the markets of another agency before the market scope was ever the only guard. Test W2c added (model query under another agency). |
| B19  | The first pass stayed green: no test used a market ulid of a sibling wave of the same agency. Test M1b added (422, nothing written).                                              |
| D5   | The first pass stayed green on both engines: the country rows kept the wave RESTRICT alive. Test MK6b added (a market with no country row and no persona).                        |

## Table

| Id  | Gate                                                           | Engine         | Under the break | After the revert |
| --- | -------------------------------------------------------------- | -------------- | --------------- | ---------------- |
| B1  | version check (Q12, 409 wave.markets_changed)                  | sqlite         | red             | green            |
| B2  | unknown market id in an edit refused                           | sqlite         | red             | green            |
| B3  | market name unique in the service (Q4)                         | sqlite         | red             | green            |
| B4  | market code unique in the service (Q5)                         | sqlite         | red             | green            |
| B5  | M2 in the service (country in two markets)                     | sqlite         | red             | green            |
| B6  | removal refused when personas exist (M6)                       | sqlite         | red             | green            |
| B7  | audit never carries a market name                              | sqlite         | red             | green            |
| B8  | audit lists renamed ulids                                      | sqlite         | red             | green            |
| B9  | limit of 20 markets                                            | sqlite         | red             | green            |
| B10 | limit of 40 countries                                          | sqlite         | red             | green            |
| B11 | short code shape 2 to 6 capitals or digits                     | sqlite         | red             | green            |
| B12 | market name at most 60                                         | sqlite         | red             | green            |
| B13 | old shape: market objects only carry the planned keys          | sqlite         | red             | green            |
| B14 | markets_version required with markets on an edit               | sqlite         | red             | green            |
| B15 | persona code prefix is the market short code (Q6)              | sqlite         | red             | green            |
| B16 | tab order of markets                                           | sqlite         | red             | green            |
| B17 | wave_markets edits need admin or manager (M7)                  | sqlite         | red             | green            |
| B18 | tenancy scope on WaveMarket                                    | sqlite         | red             | green            |
| B19 | market lookup bound to the wave (no cross-wave market)         | sqlite         | red             | green            |
| B20 | waves flag gate on markets (flagOff on update)                 | sqlite         | red             | green            |
| B21 | presenter returns the market ulid                              | sqlite         | red             | green            |
| B22 | wave resource carries markets_version                          | sqlite         | red             | green            |
| B23 | audit catalogue lists markets_renamed                          | sqlite         | red             | green            |
| B24 | erasure registry covers wave_market_countries                  | sqlite         | red             | green            |
| B25 | erasure registry covers wave_markets                           | sqlite         | red             | green            |
| D1  | DB: one market per country per wave (M2 key)                   | sqlite + pgsql | red             | green            |
| D2  | DB: market name unique ignoring case                           | sqlite + pgsql | red             | green            |
| D3  | DB: market short code unique per wave                          | sqlite + pgsql | red             | green            |
| D4  | DB: persona market must be a market of its wave (composite FK) | sqlite + pgsql | red             | green            |
| D5  | DB: a wave cannot be deleted from under its markets (RESTRICT) | sqlite + pgsql | red             | green            |
| D6  | DB: market ulid unique                                         | sqlite + pgsql | red             | green            |
| D7  | freshness: no waves.markets column                             | sqlite         | red             | green            |
| D8  | Migration: the market table sets a lock timeout                | sqlite         | red             | green            |
| D9  | Migration: no data write (a backfill is refused)               | sqlite         | red             | green            |
| V1  | Web bridge: short code to ulid in a request                    | vitest         | red             | green            |
| V2  | Web bridge: `to_market` is translated too                      | vitest         | red             | green            |
| V3  | Web bridge: ulid back to short code in a response              | vitest         | red             | green            |
| V4  | 24 locales carry every wave key (one key removed from `de`)    | vitest         | red             | green            |

Not covered by a break-revert, and why: the lock durations (measured on a throwaway PostgreSQL, see the review), the
Playwright specs (they were converted to the new contract and run serially, 22 of 22 green; a break of a browser gate
needs a running app per row and was not done), and the walk (stage 2).
