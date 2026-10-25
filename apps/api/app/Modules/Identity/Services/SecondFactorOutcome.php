<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

/** What {@see SecondFactorStepUp::verify()} decided about a code typed at a sensitive click. */
enum SecondFactorOutcome: string
{
    case Passed = 'passed';
    case Required = 'required';
    case Invalid = 'invalid';
    case RateLimited = 'rate_limited';
    case EnrollmentSuspended = 'enrollment_suspended';
    case NotEnrolled = 'not_enrolled';
}
