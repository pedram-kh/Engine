<?php

declare(strict_types=1);

namespace App\Modules\Brands\Enums;

/**
 * What a brand client file is for (PL-1.1). The four chips of the Client files table plus `other`.
 * Stored as a plain string on `brand_files.file_type`; this enum is the constraint.
 */
enum BrandFileType: string
{
    case Brief = 'brief';
    case Research = 'research';
    case Guidelines = 'guidelines';
    case Assets = 'assets';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
