# PL-1.3 break-reverts

Each gate is run on the real code, then broken, then reverted. One row per gate, recorded as it is built.

| Gate | Test file                                        | Break                                                                                               | Failed as expected                               | Reverted and green                     |
| ---- | ------------------------------------------------ | --------------------------------------------------------------------------------------------------- | ------------------------------------------------ | -------------------------------------- |
| K1   | `Waves/WaveStepSchemaTest.php` (SQLite)          | rename one step key (`WaveStepKey.php`)                                                             | yes: Tests: 1 failed (1 assertions)              | yes: Tests: 1 passed (30 assertions)   |
| E1   | `Erasure/ErasureRegistrySchemaTest.php` (SQLite) | remove the wave_steps registry entry (`ErasureRegistry.php`)                                        | yes: Tests: 1 failed, 12 passed (346 assertions) | yes: Tests: 13 passed (346 assertions) |
| M1   | `Waves/WaveStepSchemaTest.php` (PostgreSQL)      | drop the unique (wave_id, step_key) index (`2026_11_19_100000_create_wave_steps_table.php`)         | yes: Tests: 1 failed, 1 passed (2 assertions)    | yes: Tests: 2 passed (3 assertions)    |
| M1b  | `Waves/WaveStepSchemaTest.php` (PostgreSQL)      | drop the unique (wave_id, stream, position) index (`2026_11_19_100000_create_wave_steps_table.php`) | yes: Tests: 1 failed, 1 passed (3 assertions)    | yes: Tests: 2 passed (3 assertions)    |
| P1   | `Waves/WaveStepProvisionerTest.php` (SQLite)     | ignore steps_provisioned_at (`WaveStepProvisioner.php`)                                             | yes: Tests: 1 failed, 1 passed (12 assertions)   | yes: Tests: 2 passed (13 assertions)   |
| P3a  | `Waves/WaveStepProvisionerTest.php` (PostgreSQL) | remove the lockForUpdate (`WaveStepProvisioner.php`)                                                | yes: Tests: 1 failed, 1 passed (6 assertions)    | yes: Tests: 2 passed (6 assertions)    |
| P3b  | `Waves/WaveStepProvisionerTest.php` (SQLite)     | remove the unique-violation catch (the loser errors) (`WaveStepProvisioner.php`)                    | yes: Tests: 1 failed (0 assertions)              | yes: Tests: 1 passed (5 assertions)    |
| P2   | `Waves/WaveStepProvisionerTest.php` (SQLite)     | swallow a provisioning failure (the wave survives it) (`WaveService.php`)                           | yes: Tests: 1 failed, 2 passed (6 assertions)    | yes: Tests: 3 passed (7 assertions)    |
