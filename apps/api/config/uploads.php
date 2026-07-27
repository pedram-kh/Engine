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

    /*
    |--------------------------------------------------------------------------
    | Brand logo upload cap (bytes)
    |--------------------------------------------------------------------------
    |
    | AH-053 (D7). Same machinery as the avatar cap: BrandLogoUploadService's
    | size guard and BrandLogoController's `max:` rule both derive from this
    | value. Logos are small square marks rather than photographs, so the cap
    | is deliberately tighter than the avatar's.
    |
    | Every cap registered here is covered by the `/health` upload assertion:
    | UploadLimitChecker::requiredBytes() returns the LARGEST of them, so the
    | check stays honest as caps are added (a runtime configured below the
    | biggest advertised cap is flagged).
    |
    */

    'brand_logo_max_bytes' => (int) env('UPLOAD_BRAND_LOGO_MAX_BYTES', 2 * 1024 * 1024),

];
