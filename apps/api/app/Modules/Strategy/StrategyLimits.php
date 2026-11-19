<?php

declare(strict_types=1);

namespace App\Modules\Strategy;

/**
 * The PL-2.1 limits (plan section 1.6, ruled Q8): one place, read by the request rules, the services and the summary
 * endpoint (the web shows the same numbers). Lengths are CHARACTERS (mb_strlen), after trimming.
 */
final class StrategyLimits
{
    public const USP_MAX = 6;

    public const USP_WARN_BELOW = 4;

    public const PERSONA_MIN = 2;

    public const PERSONA_MAX = 8;

    public const AUDIENCE_TITLE = 80;

    public const USP_TITLE = 40;

    public const USP_EXPLAINER = 400;

    public const USP_CREATIVE_ANGLE = 300;

    public const USP_COPY_SEED = 300;

    public const PERSONA_CODE_MIN = 2;

    public const PERSONA_CODE_MAX = 8;

    public const PERSONA_TITLE = 60;

    public const PERSONA_NAME = 40;

    public const PERSONA_SUMMARY = 300;

    public const PERSONA_PARAGRAPH = 300;

    public const LIST_ITEMS = 6;

    public const LIST_ITEM = 120;

    public const ANGLE_EXPLANATION = 140;

    /** @return array<string, int> */
    public static function all(): array
    {
        return [
            'usp_max' => self::USP_MAX,
            'usp_warn_below' => self::USP_WARN_BELOW,
            'persona_min' => self::PERSONA_MIN,
            'persona_max' => self::PERSONA_MAX,
            'audience_title' => self::AUDIENCE_TITLE,
            'usp_title' => self::USP_TITLE,
            'usp_explainer' => self::USP_EXPLAINER,
            'usp_creative_angle' => self::USP_CREATIVE_ANGLE,
            'usp_copy_seed' => self::USP_COPY_SEED,
            'persona_code_min' => self::PERSONA_CODE_MIN,
            'persona_code_max' => self::PERSONA_CODE_MAX,
            'persona_title' => self::PERSONA_TITLE,
            'persona_name' => self::PERSONA_NAME,
            'persona_summary' => self::PERSONA_SUMMARY,
            'persona_paragraph' => self::PERSONA_PARAGRAPH,
            'list_items' => self::LIST_ITEMS,
            'list_item' => self::LIST_ITEM,
            'angle_explanation' => self::ANGLE_EXPLANATION,
        ];
    }
}
