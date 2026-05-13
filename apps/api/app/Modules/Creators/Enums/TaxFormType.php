<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Tax-form classification for the creator. The four EU + UK forms cover
 * Phase 1's launch markets per docs/20-PHASE-1-SPEC.md §6.1 Step 6.
 *
 * Stored as varchar(16) on creator_tax_profiles.tax_form_type. See
 * docs/03-DATA-MODEL.md §5.
 */
enum TaxFormType: string
{
    case EuSelfEmployed = 'eu_self_employed';
    case EuCompany = 'eu_company';
    case UkSelfEmployed = 'uk_self_employed';
    case UkCompany = 'uk_company';
}
