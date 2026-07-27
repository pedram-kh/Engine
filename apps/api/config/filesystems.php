<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT_URL', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Sprint 3 Chunk 1 — module-scoped object-storage disks.
        |----------------------------------------------------------------------
        |
        | Each module opts into its disk explicitly via Storage::disk('media')
        | / Storage::disk('contracts') / Storage::disk('exports') /
        | Storage::disk('media-public'). The default FILESYSTEM_DISK stays
        | 'local' so test ephemera continues to use the local driver — modules
        | name their disk at every call site.
        |
        | Local dev: backed by MinIO (docker-compose) at http://localhost:9100.
        | Buckets bootstrapped by `minio-init`:
        |   - catalyst-engine-media          (private — avatars, portfolio)
        |   - catalyst-engine-contracts      (private — signed contracts)
        |   - catalyst-engine-exports        (private — GDPR/CSV exports)
        |   - catalyst-engine-public         (public-read — only assets meant
        |                                     to be served unauthenticated)
        |
        | The `media-public` disk is named `media-public` not `public` to avoid
        | collision with Laravel's default `public` disk (D-pause-5 in the
        | Sprint 3 Chunk 1 read pass).
        |
        | Production / staging: same disk names; AWS_BUCKET_* env vars point at
        | real S3 buckets per AWS Secrets Manager. AWS_ENDPOINT_URL is unset
        | in those environments (Laravel's S3 driver defaults to AWS endpoints).
        */

        /*
        |----------------------------------------------------------------------
        | AH-053 — the one test-only branch in this file.
        |----------------------------------------------------------------------
        |
        | The Playwright suites have no object store: the E2E CI job provisions
        | Postgres and Redis only, and `make dev`'s MinIO is not part of it. An
        | S3 write against an absent bucket does not raise here (every disk in
        | this file is `'throw' => false`), so a brand-logo upload would answer
        | 200 having stored nothing and the spec would go green over a lost
        | file. `MEDIA_DISK_DRIVER=local` swaps the DRIVER for that run so the
        | whole pipeline — write, signed URL, browser render — is exercised for
        | real against the local filesystem.
        |
        | `'serve' => true` is what gives the local driver a working
        | `temporaryUrl()`: Laravel registers a signed `storage.media` route
        | (LocalFilesystemAdapter::temporaryUrl), so `BrandLogoUploadService::
        | signedViewUrl()` keeps its exact production semantics — a
        | short-lived, signature-checked GET — instead of being special-cased.
        |
        | The explicit `url` is load-bearing, not cosmetic. Without it the
        | served route defaults to `/storage/{path}` — the SAME URI the `local`
        | disk above already registers, and `{path}` is a `.*` wildcard, so the
        | first-registered route wins and `storage.local` silently swallows
        | every `/storage/media/...` request. The signature still validates (it
        | is computed over the URL, not the route), the wrong disk is then
        | asked for `media/agencies/...`, and the logo 404s. `/e2e-media` keeps
        | the two off each other.
        |
        | The variable is set in exactly one place, `apps/main/playwright.
        | config.ts`, and pinned there by `tests/unit/architecture/
        | e2e-media-disk.spec.ts`. Unset — every other environment, production
        | included — this resolves to the untouched S3 definition below.
        */
        'media' => env('MEDIA_DISK_DRIVER', 's3') === 'local'
            ? [
                'driver' => 'local',
                'root' => storage_path('app/e2e-media'),
                'url' => env('APP_URL').'/e2e-media',
                'serve' => true,
                'visibility' => 'private',
                'throw' => false,
                'report' => false,
            ]
            : [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('AWS_BUCKET_MEDIA', env('AWS_BUCKET', 'catalyst-engine-media')),
                'endpoint' => env('AWS_ENDPOINT_URL', env('AWS_ENDPOINT')),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                'visibility' => 'private',
                'throw' => false,
                'report' => false,
            ],

        'contracts' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET_CONTRACTS', 'catalyst-engine-contracts'),
            'endpoint' => env('AWS_ENDPOINT_URL', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        'exports' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET_EXPORTS', 'catalyst-engine-exports'),
            'endpoint' => env('AWS_ENDPOINT_URL', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        'media-public' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET_PUBLIC', 'catalyst-engine-public'),
            'endpoint' => env('AWS_ENDPOINT_URL', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
