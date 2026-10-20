<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Registry;

/**
 * What an ANON column is overwritten with. This is the executable half of the
 * registry: Stage 4 applies these, it does not re-decide them.
 *
 * `Null` is only legal on a nullable column (the verifier reads the schema and
 * fails otherwise): a NOT NULL column needs a sentinel, never a migration
 * (PII map §2.5). The encrypted variants exist because an `encrypted` cast
 * cannot hold a plain string: the engine writes real ciphertext.
 */
enum AnonValue: string
{
    case Null = 'null';

    /** The fixed, never-rendered sentinel `[erased]`. */
    case Erased = 'erased';

    /** `[]` / `{}` for a JSON or array column. */
    case EmptyArray = 'empty-array';

    case False = 'false';

    /** `erased-{ulid}@erased.invalid` (unique, undeliverable by RFC 6761). */
    case EmailPlaceholder = 'email-placeholder';

    /** A fresh random hash nobody knows the plaintext of. */
    case RandomPasswordHash = 'random-password-hash';

    /** `Crypt::encryptString('[erased]')` for an `encrypted` column. */
    case EncryptedErased = 'encrypted-erased';

    /** `Crypt::encryptString('[]')` for an `encrypted:array` column. */
    case EncryptedEmptyArray = 'encrypted-empty-array';

    /** The erasure marker: set to the run's timestamp. */
    case Now = 'now';
}
