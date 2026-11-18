<?php

declare(strict_types=1);

namespace App\Modules\Waves\Steps;

/**
 * The only place step keys are spelled (PL-1.3 plan section 1.3). A later sprint that ticks a step imports a
 * constant from here, never a string literal. A key never changes when a person renames the step.
 */
final class WaveStepKey
{
    public const UGC_KICK_OFF_CALL = 'ugc.kick_off_call';

    public const UGC_USPS = 'ugc.usps';

    public const UGC_PERSONAS = 'ugc.personas';

    public const UGC_MATRIX = 'ugc.matrix';

    public const UGC_CONCEPTS = 'ugc.concepts';

    public const UGC_CREATORS = 'ugc.creators';

    public const UGC_SCRIPTS = 'ugc.scripts';

    public const UGC_FOOTAGE_RECEIVED = 'ugc.footage_received';

    public const UGC_EDITING = 'ugc.editing';

    public const UGC_INTERNAL_REVIEW = 'ugc.internal_review';

    public const UGC_EDITING_FEEDBACK_1 = 'ugc.editing_feedback_1';

    public const UGC_EDITING_FEEDBACK_2 = 'ugc.editing_feedback_2';

    public const UGC_CLIENT_APPROVAL = 'ugc.client_approval';

    public const STATICS_BRIEF_REFERENCES = 'statics.brief_references';

    public const STATICS_DESIGN_V1 = 'statics.design_v1';

    public const STATICS_INTERNAL_REVIEW = 'statics.internal_review';

    public const STATICS_CLIENT_FEEDBACK = 'statics.client_feedback';

    public const STATICS_FINAL_EXPORT = 'statics.final_export';

    public const STILLS_SHOT_LIST = 'stills.shot_list';

    public const STILLS_SHOOT_PREP = 'stills.shoot_prep';

    public const STILLS_SHOOT_DAY = 'stills.shoot_day';

    public const STILLS_RETOUCHING_SELECTS = 'stills.retouching_selects';

    public const STILLS_INTERNAL_REVIEW = 'stills.internal_review';

    public const STILLS_CLIENT_APPROVAL = 'stills.client_approval';

    /**
     * The six strategy steps, in strategy order (ruling Q2). They feed the six segments of the Brand page bar, can
     * be renamed but not removed and not moved to another stream.
     *
     * @var list<string>
     */
    public const STRATEGY = [
        self::UGC_USPS,
        self::UGC_PERSONAS,
        self::UGC_MATRIX,
        self::UGC_CONCEPTS,
        self::UGC_CREATORS,
        self::UGC_SCRIPTS,
    ];

    public static function isStrategy(?string $key): bool
    {
        return $key !== null && in_array($key, self::STRATEGY, true);
    }
}
