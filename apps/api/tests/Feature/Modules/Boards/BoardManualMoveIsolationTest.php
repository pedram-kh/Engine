<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

/**
 * Structural proof of D-8: the manual-move path has NO reference to the
 * assignment state machine. Board state is a visualization layer (§4.4) — the
 * state machine stays the single status authority ("no controller flips status
 * directly"). This source-inspection test pins the invariant against future
 * drift even if a behavioural test were to miss a sneaked-in call.
 */
it('the manual-move path never references CampaignAssignmentStateMachine (D-8)', function (string $relativePath): void {
    $source = file_get_contents(base_path($relativePath));
    expect($source)->not->toBeFalse();

    // Strip doc-comments / comments so we assert on EXECUTABLE code only — the
    // doc-blocks deliberately discuss the invariant by name.
    $code = '';
    foreach (token_get_all((string) $source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    expect($code)->not->toContain('CampaignAssignmentStateMachine')
        ->and($code)->not->toContain('StateMachine');
})->with([
    'app/Modules/Boards/Services/BoardCardMoveService.php',
    'app/Modules/Boards/Http/Controllers/BoardCardController.php',
    'app/Modules/Boards/Services/BoardColumnService.php',
]);
