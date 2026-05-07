<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default hash driver that will be used to hash
    | passwords for your application. By default, the Argon2id algorithm is
    | used; it is the OWASP-recommended algorithm and is required by
    | docs/05-SECURITY-COMPLIANCE.md §6.1 ("Hashed with Argon2id").
    |
    | Supported: "bcrypt", "argon", "argon2id"
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    |
    | Retained for completeness and Sanctum's hashed-token paths. Phase 1
    | passwords use Argon2id (see "argon" block below); bcrypt is not used
    | for user-account passwords.
    |
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon Options
    |--------------------------------------------------------------------------
    |
    | These are the Argon2id parameters used to hash every user password.
    | Defaults follow OWASP "Password Storage Cheat Sheet" guidance for
    | Argon2id with a memory-hard configuration that fits comfortably on
    | modern Fargate/RDS-class hardware while keeping login latency under
    | the budgets defined in docs/00-MASTER-ARCHITECTURE.md §16.
    |
    | Tuning notes:
    |   - memory:  KiB. 65536 = 64 MiB. Lower if a low-RAM container balks.
    |   - time:    iterations. 4 is OWASP's minimum recommendation for
    |              Argon2id with 64 MiB memory.
    |   - threads: parallelism. 1 keeps single-thread reproducibility for
    |              tests; raise carefully on multi-core production hosts.
    |
    | When these parameters change, App\Modules\Identity\Services\LoginService
    | calls Hash::needsRehash() on every successful login and re-hashes the
    | stored password transparently — so increasing strength later is safe.
    |
    */

    'argon' => [
        'memory' => (int) env('HASH_ARGON_MEMORY', 65536),
        'threads' => (int) env('HASH_ARGON_THREADS', 1),
        'time' => (int) env('HASH_ARGON_TIME', 4),
        'verify' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rehash On Login
    |--------------------------------------------------------------------------
    |
    | Whether Laravel's password reset / change services should automatically
    | rehash the user's password if its current hash uses outdated parameters.
    | Always true here — see Hash::needsRehash() use in LoginService.
    |
    */

    'rehash_on_login' => true,

];
