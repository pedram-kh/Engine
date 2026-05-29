<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Avatar upload cap (bytes)
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the maximum creator-avatar upload size.
    | Both the request-validation rule (AvatarController) and the magic-byte
    | size guard (AvatarUploadService) derive from this value, and the
    | `/health` upload assertion + `uploads:check-limits` command verify the
    | PHP runtime (`upload_max_filesize` / `post_max_size`) can actually
    | accept it.
    |
    | NOTE: the runtime limits live OUTSIDE the application (PHP ini + any
    | reverse proxy's body-size cap). Raising this number alone does not
    | raise those limits — see docs/runbooks/local-dev.md. The health check
    | exists precisely so a runtime that is configured below this value is
    | flagged instead of silently dropping large uploads.
    |
    */

    'avatar_max_bytes' => (int) env('UPLOAD_AVATAR_MAX_BYTES', 5 * 1024 * 1024),

];
