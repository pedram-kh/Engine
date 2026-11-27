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

    /** PL-2.2: the rationale and the message line of a matrix cell (the message line becomes the synced angle). */
    public const MATRIX_LINE = 140;

    /** PL-2.2: cells in one write request (a market holds at most 8 personas times 6 USPs). */
    public const MATRIX_BATCH_MAX = 48;

    /** PL-2.3: concepts (plan section 1.10 and ruling Q5: at most 30 per market). */
    public const CONCEPT_MAX_PER_MARKET = 30;

    public const CONCEPT_MIN_RECOMMENDED = 3;

    public const CONCEPT_TITLE = 120;

    public const CONCEPT_FUNNEL_NOTE = 40;

    public const CONCEPT_PRODUCT = 160;

    public const CONCEPT_ANGLE = 300;

    public const CONCEPT_PRIMARY_MESSAGE = 300;

    public const CONCEPT_HYPOTHESIS = 400;

    public const CONCEPT_HOOK = 300;

    public const CONCEPT_FORMAT_VISUAL = 400;

    public const CONCEPT_PAYOFF = 300;

    public const CONCEPT_CTA = 200;

    public const CONCEPT_KEY_POINTS = 8;

    public const CONCEPT_KEY_POINT = 160;

    public const CLIENT_NAME = 80;

    public const DECISION_NOTE = 500;

    /** PL-3.1 (Q10): creators per market and per wave; (Q1) the slot code. */
    public const CREATORS_PER_MARKET = 30;

    public const CREATORS_PER_WAVE = 100;

    public const CREATOR_SLOT_CODE_MIN = 2;

    public const CREATOR_SLOT_CODE_MAX = 10;

    public const DECISION_COMMENT = 1000;

    public const LINK_TITLE = 120;

    public const LINK_URL = 2048;

    public const LINKS_PER_CONCEPT = 10;

    public const IMAGES_PER_CONCEPT = 10;

    /** 300 MB in bytes: the total of one concept's reference images (ruling C4: no separate per-image cap). */
    public const IMAGE_TOTAL_BYTES = 300 * 1024 * 1024;

    public const IMAGE_FILENAME = 255;

    /** PL-3.2a: scripts per creator per wave (a database fact: CHECK and unique key on `wave_scripts`). */
    public const SCRIPTS_PER_CREATOR = 3;

    /** PL-3.2a: hooks per script (a new script starts with three: A, B, C). */
    public const HOOKS_PER_SCRIPT = 6;

    public const HOOKS_ON_CREATE = 3;

    public const SCRIPT_ANGLE = 300;

    public const HOOK_AUDIO = 300;

    public const HOOK_OVERLAY = 100;

    public const HOOK_VISUAL = 400;

    /** PL-3.2b: lines per script, B-roll rows per SCRIPT (40 by the walk ruling: the 12 of the plan was a planning number and too low; the frame's own sample has 13), and the text lengths. */
    public const LINES_PER_SCRIPT = 30;

    public const BROLL_PER_SCRIPT = 40;

    public const LINE_TEXT = 600;

    public const BROLL_DESCRIPTION = 400;

    /** PL-3.2b (ruling 4): English is always there, plus at most three LOCAL languages per market. */
    public const LOCAL_LANGUAGES_PER_MARKET = 3;

    /** PL-3.2b (ruling 11): reference images of a hook or a line, and the total of one script. */
    public const IMAGES_PER_BLOCK = 10;

    public const SCRIPT_IMAGE_TOTAL_BYTES = 300 * 1024 * 1024;

    /** PL-3.2a: the durations a script can have, in seconds (the default is the first one marked below). */
    public const SCRIPT_DURATIONS = [15, 20, 30, 45, 60];

    public const SCRIPT_DURATION_DEFAULT = 30;

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
            'matrix_line' => self::MATRIX_LINE,
            'matrix_batch_max' => self::MATRIX_BATCH_MAX,
            'concept_max_per_market' => self::CONCEPT_MAX_PER_MARKET,
            'concept_min_recommended' => self::CONCEPT_MIN_RECOMMENDED,
            'concept_title' => self::CONCEPT_TITLE,
            'concept_funnel_note' => self::CONCEPT_FUNNEL_NOTE,
            'concept_product' => self::CONCEPT_PRODUCT,
            'concept_angle' => self::CONCEPT_ANGLE,
            'concept_primary_message' => self::CONCEPT_PRIMARY_MESSAGE,
            'concept_hypothesis' => self::CONCEPT_HYPOTHESIS,
            'concept_hook' => self::CONCEPT_HOOK,
            'concept_format_visual' => self::CONCEPT_FORMAT_VISUAL,
            'concept_payoff' => self::CONCEPT_PAYOFF,
            'concept_cta' => self::CONCEPT_CTA,
            'concept_key_points' => self::CONCEPT_KEY_POINTS,
            'concept_key_point' => self::CONCEPT_KEY_POINT,
            'client_name' => self::CLIENT_NAME,
            'creators_per_market' => self::CREATORS_PER_MARKET,
            'creators_per_wave' => self::CREATORS_PER_WAVE,
            'decision_note' => self::DECISION_NOTE,
            'decision_comment' => self::DECISION_COMMENT,
            'link_title' => self::LINK_TITLE,
            'links_per_concept' => self::LINKS_PER_CONCEPT,
            'images_per_concept' => self::IMAGES_PER_CONCEPT,
            'image_total_bytes' => self::IMAGE_TOTAL_BYTES,
            'scripts_per_creator' => self::SCRIPTS_PER_CREATOR,
            'hooks_per_script' => self::HOOKS_PER_SCRIPT,
            'script_angle' => self::SCRIPT_ANGLE,
            'hook_audio' => self::HOOK_AUDIO,
            'hook_overlay' => self::HOOK_OVERLAY,
            'hook_visual' => self::HOOK_VISUAL,
            'lines_per_script' => self::LINES_PER_SCRIPT,
            'broll_per_script' => self::BROLL_PER_SCRIPT,
            'line_text' => self::LINE_TEXT,
            'broll_description' => self::BROLL_DESCRIPTION,
            'local_languages_per_market' => self::LOCAL_LANGUAGES_PER_MARKET,
            'images_per_block' => self::IMAGES_PER_BLOCK,
            'script_image_total_bytes' => self::SCRIPT_IMAGE_TOTAL_BYTES,
        ];
    }
}
