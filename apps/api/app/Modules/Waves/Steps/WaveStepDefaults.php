<?php

declare(strict_types=1);

namespace App\Modules\Waves\Steps;

use App\Modules\Waves\Enums\WaveStream;

/**
 * The ready-made step list of a new wave (PL-1.3 plan section 1.4, ruling T1 and Q2): a pure list, like
 * `BoardDefaults::columns()`, pinned by a test. Names are stored in English (ruling Q2). Positions are 10, 20, 30
 * inside each stream. Only the three steps the frame shows as client-owned default to owner "Client".
 */
final class WaveStepDefaults
{
    /**
     * @return list<array{stream: WaveStream, key: string, name: string, position: int, owner_client: bool}>
     */
    public static function steps(): array
    {
        $out = [];
        foreach (self::definition() as $streamValue => $rows) {
            $stream = WaveStream::from($streamValue);
            foreach ($rows as $i => [$key, $name, $client]) {
                $out[] = [
                    'stream' => $stream,
                    'key' => $key,
                    'name' => $name,
                    'position' => ($i + 1) * 10,
                    'owner_client' => $client,
                ];
            }
        }

        return $out;
    }

    /**
     * The default steps of one stream (used by Add stream).
     *
     * @return list<array{stream: WaveStream, key: string, name: string, position: int, owner_client: bool}>
     */
    public static function forStream(WaveStream $stream): array
    {
        return array_values(array_filter(self::steps(), static fn (array $s): bool => $s['stream'] === $stream));
    }

    /**
     * @return array<string, list<array{0: string, 1: string, 2: bool}>>
     */
    private static function definition(): array
    {
        return [
            WaveStream::UgcVideo->value => [
                [WaveStepKey::UGC_KICK_OFF_CALL, 'Kick-off call', false],
                [WaveStepKey::UGC_USPS, 'USPs', false],
                [WaveStepKey::UGC_PERSONAS, 'Personas', false],
                [WaveStepKey::UGC_MATRIX, 'Messaging matrix', false],
                [WaveStepKey::UGC_CONCEPTS, 'Concepts', false],
                [WaveStepKey::UGC_CREATORS, 'Creators', false],
                [WaveStepKey::UGC_SCRIPTS, 'Scripts', false],
                [WaveStepKey::UGC_FOOTAGE_RECEIVED, 'Footage received', false],
                [WaveStepKey::UGC_EDITING, 'Editing', false],
                [WaveStepKey::UGC_INTERNAL_REVIEW, 'Internal review', false],
                [WaveStepKey::UGC_EDITING_FEEDBACK_1, '1st editing feedback', false],
                [WaveStepKey::UGC_EDITING_FEEDBACK_2, '2nd editing feedback', false],
                [WaveStepKey::UGC_CLIENT_APPROVAL, 'Client approval', true],
            ],
            WaveStream::Statics->value => [
                [WaveStepKey::STATICS_BRIEF_REFERENCES, 'Static brief & references', false],
                [WaveStepKey::STATICS_DESIGN_V1, 'Design v1', false],
                [WaveStepKey::STATICS_INTERNAL_REVIEW, 'Internal review', false],
                [WaveStepKey::STATICS_CLIENT_FEEDBACK, 'Client feedback round', true],
                [WaveStepKey::STATICS_FINAL_EXPORT, 'Final export & resizes', false],
            ],
            WaveStream::Stills->value => [
                [WaveStepKey::STILLS_SHOT_LIST, 'Shot list', false],
                [WaveStepKey::STILLS_SHOOT_PREP, 'Shoot prep', false],
                [WaveStepKey::STILLS_SHOOT_DAY, 'Shoot day', false],
                [WaveStepKey::STILLS_RETOUCHING_SELECTS, 'Retouching & selects', false],
                [WaveStepKey::STILLS_INTERNAL_REVIEW, 'Internal review', false],
                [WaveStepKey::STILLS_CLIENT_APPROVAL, 'Client approval', true],
            ],
        ];
    }
}
