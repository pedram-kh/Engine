# PL-3.1 break-reverts

Each gate was broken on purpose, the named test run (it must go RED), the break reverted, the test run again (it must go GREEN).
Run on SQLite unless noted. A row is written the moment its check is done.

| #   | Sub-step  | Gate                                    | What was broken                                                                | Test that went red               | Result                         |
| --- | --------- | --------------------------------------- | ------------------------------------------------------------------------------ | -------------------------------- | ------------------------------ |
| 1   | A flag    | active() needs all three flags          | removed `&& StrategyEnabled::active()` from `CreatorsScriptsEnabled::active()` | `CreatorsFlagTest`               | 2 red broken, 4 green restored |
| 2   | A schema  | one creator per wave                    | removed the unique `(wave_id, creator_id)`                                     | `M1: one creator per wave`       | red broken, green restored     |
| 3   | A erasure | Q6 selection is the subject's rows only | selection predicate `creator_id = -1`                                          | `WaveCreatorDecisionErasureTest` | red broken, green restored     |
| 4   | A erasure | Q6 comment is scrubbed                  | `comment` classified KEEP in the registry                                      | `WaveCreatorDecisionErasureTest` | red broken, green restored     |
