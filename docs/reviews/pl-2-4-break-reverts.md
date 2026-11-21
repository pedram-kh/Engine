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

---

# PL-2.4 Stage 2 (web): break-revert record

Method, for every gate: patch the code, run the targeted tests and require them **red**, restore the file in a `finally`,
re-run the same tests and require them **green**. `git status` after the runs showed no source change besides the tests
added on purpose. Drivers: `/tmp/pl24/br.py` (Vitest) and `/tmp/pl24/br2.py` (Playwright and Pest). Vitest gates ran with
`VITEST_MAX_WORKERS=4`; Playwright serial on the shared e2e database `catalyst_e2e`.

49 gates, 49 red under the break, 49 green after the revert.

## Gates the first pass found weak (fixed, then re-run red)

| Gate          | What the first pass showed                                                                         | Fix                                                                                              |
| ------------- | -------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| W3            | Two `slice(0, 6)` guard the code length; breaking one stayed green                                 | The gate breaks both; a test with ten initials and a taken-suffix case was added                 |
| W17           | No test for the 40-country limit in the web                                                        | `MarketBuilder.spec.ts` (disabled add chip and the limit text at 40)                             |
| W31, W32, W33 | The kit gave a market the same text as an id, but the dialog titles were never read                | Assertions on `Add persona · Slovakia`, `Add concept · Slovakia` and the copy dialog source name |
| W36           | The kit used the short code as the market id, so a tab that emitted the code was indistinguishable | A test with ULID-shaped ids (`selects by the market ULID, never by the short code`)              |
| W37           | No malformed entry without a name in the market_has_personas meta                                  | Two entries (no name, no market name) added to the malformed list                                |
| P1            | The hover assertion looked for `Ireland`, which is also in the market name "UK & Ireland"          | It now looks for `United Kingdom`                                                                |
| P-all         | After the country list was kept open for several picks, the Playwright spec clicked a covered chip | The spec picks the next country in the open list                                                 |

## Table

| Id   | Gate                                                       | Tests                 | Under the break | After the revert |
| ---- | ---------------------------------------------------------- | --------------------- | --------------- | ---------------- |
| W1   | suggested code of a one-country market is the country code | Vitest                | red             | green            |
| W2   | suggested code made unique with a numeric suffix           | Vitest                | red             | green            |
| W3   | suggested code at most six characters (both slices)        | Vitest                | red             | green            |
| W4   | market name required                                       | Vitest                | red             | green            |
| W5   | market names compared ignoring case and spacing            | Vitest                | red             | green            |
| W6   | market name at most 60                                     | Vitest                | red             | green            |
| W7   | short code shape 2 to 6 capitals or digits                 | Vitest                | red             | green            |
| W8   | a market needs a country                                   | Vitest                | red             | green            |
| W9   | duplicate short codes refused                              | Vitest                | red             | green            |
| W10  | an untouched name follows the countries                    | Vitest                | red             | green            |
| W11  | an untouched code follows the name                         | Vitest                | red             | green            |
| W12  | an existing market keeps its id in the request             | Vitest                | red             | green            |
| W13  | an empty code is left out so the server derives one        | Vitest                | red             | green            |
| W14  | a country in another market is unavailable                 | Vitest                | red             | green            |
| W15  | the unavailable country says which market has it           | Vitest                | red             | green            |
| W16  | limit of 20 markets (add is disabled)                      | Vitest                | red             | green            |
| W17  | limit of 40 countries per market                           | Vitest                | red             | green            |
| W17b | add-country is disabled at the limit                       | Vitest                | red             | green            |
| W18  | an edit sends markets only when they changed               | Vitest                | red             | green            |
| W19  | 409 wave.markets_changed shows the reload button (G5)      | Vitest                | red             | green            |
| W20  | the reload resets the drafts from the server               | Vitest                | red             | green            |
| W21  | market_has_personas names the markets with counts          | Vitest                | red             | green            |
| W22  | country_in_two_markets message                             | Vitest                | red             | green            |
| W23  | market error shown in the dialog                           | Vitest                | red             | green            |
| W24  | market tab prints the NAME                                 | Vitest                | red             | green            |
| W25  | tab info button has an accessible name                     | Vitest                | red             | green            |
| W26  | info list opens on tap with the full name as title         | Vitest                | red             | green            |
| W27  | info list shows each country (code and name)               | Vitest                | red             | green            |
| W28  | waves table prints market names                            | Vitest                | red             | green            |
| W29  | waves table has the countries info                         | Vitest                | red             | green            |
| W30  | markets directory returns the name                         | Vitest                | red             | green            |
| W31  | persona dialog prints the market name                      | Vitest                | red             | green            |
| W32  | concept dialog title prints the market name                | Vitest                | red             | green            |
| W33  | copy dialog prints market names                            | Vitest                | red             | green            |
| W34  | USP delete list prints market names                        | Vitest                | red             | green            |
| W35  | persona warning prints the market name                     | Vitest                | red             | green            |
| W36  | the strategy address carries the market ULID               | Vitest                | red             | green            |
| W37  | market_has_personas meta reader requires the name          | Vitest                | red             | green            |
| W38  | country_in_two_markets meta reader                         | Vitest                | red             | green            |
| W39  | no v-html in a market component                            | Vitest                | red             | green            |
| W40  | 24 locales carry every market key                          | Vitest                | red             | green            |
| P1   | hover tooltip lists the countries                          | Playwright (chromium) | red             | green            |
| P2   | wave page prints the market name                           | Playwright (chromium) | red             | green            |
| P3   | builder: single country fills the name                     | Playwright (chromium) | red             | green            |
| P4   | waves table countries info opens                           | Playwright (chromium) | red             | green            |
| S1   | seeder: the Nordics market of wave 1                       | Pest (SQLite)         | red             | green            |
| S2   | seeder: personas in the multi-country markets              | Pest (SQLite)         | red             | green            |
| S3   | seeder: wave 2 is the minimum (one market)                 | Pest (SQLite)         | red             | green            |
| S4   | seeder: a second agency                                    | Pest (SQLite)         | red             | green            |
