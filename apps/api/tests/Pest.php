<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Note: Pest's `pest()->extend(...)->in('Feature')` global binding uses PHP
| glob() under the hood, which mis-handles literal `[` `]` characters in
| filesystem paths. Until that is fixed upstream (or the workspace path no
| longer contains brackets), each Feature test file explicitly calls
| `uses(\Tests\TestCase::class);` to bind to Laravel's testing TestCase.
|
*/

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));
