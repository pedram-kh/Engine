<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Account erasure (AH-104a)
|--------------------------------------------------------------------------
|
| No secret here. The erasure has no route and no schedule; these are the
| switches an operator may need on the one server that runs it.
|
*/

return [

    /*
     * Also delete every stored VERSION of a file when the media disk is S3.
     *
     * Leave true. On a versioned bucket a plain delete only adds a delete
     * marker and the old bytes stay readable by version id. It needs
     * s3:ListBucketVersions and s3:DeleteObjectVersion for the app role; if
     * those are missing the run stops before it changes the database. Set false
     * only knowingly (the command then prints a warning that versions remain).
     */
    'purge_object_versions' => env('ERASURE_PURGE_OBJECT_VERSIONS', true),

];
