<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Execution;

use App\Modules\Erasure\Registry\AnonValue;
use App\Modules\Erasure\Subject\SubjectSet;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Turns a registry {@see AnonValue} into the value written to the column. The
 * registry decides; this only executes. One value per call: the encrypted
 * variants and the password hash are fresh each time (a new IV, a new salt), so
 * the caller asks per row where rows must not share a ciphertext.
 */
final class AnonResolver
{
    public const ERASED = '[erased]';

    public static function resolve(AnonValue $value, SubjectSet $subject, string $now): mixed
    {
        return match ($value) {
            AnonValue::Null => null,
            AnonValue::Erased => self::ERASED,
            AnonValue::EmptyArray => '[]',
            AnonValue::False => false,
            AnonValue::EmailPlaceholder => 'erased-'.strtolower($subject->userUlid).'@erased.invalid',
            AnonValue::RandomPasswordHash => Hash::make(Str::random(64)),
            AnonValue::EncryptedErased => Crypt::encryptString(self::ERASED),
            AnonValue::EncryptedEmptyArray => Crypt::encryptString('[]'),
            AnonValue::Now => $now,
        };
    }
}
