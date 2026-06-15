<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Core\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for `PATCH /api/v1/me` and `PATCH /api/v1/admin/me`.
 *
 * The locale-only self-update surface: a signed-in user persists their
 * chosen UI language so it survives reload/login (docs/00-MASTER-ARCHITECTURE
 * §13). The rule set is intentionally a single field — only
 * `preferred_language` is validated, so only it can ever be written; no
 * other profile attribute is reachable through this request.
 *
 * `preferred_language` is a UI locale, so it validates against the RENDERED
 * subset (`Locale::UI_LOCALES`), not the full 24 EU languages: accepting a
 * UI locale we cannot render would let the SPA store a value that silently
 * falls back to `en`. Content-language fields (creator languages, agency /
 * brand default language) validate against the full `Locale` enum elsewhere.
 */
final class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'preferred_language' => ['required', 'string', Rule::in(Locale::UI_LOCALES)],
        ];
    }
}
