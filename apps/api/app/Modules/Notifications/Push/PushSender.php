<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

/**
 * The one seam between "we have a message for a device" and a push provider
 * (AH-102, plan §5.1).
 *
 * Everything above this interface — eligibility, copy, the job, recording, the
 * token endpoints — is provider-neutral. Everything below it (JSON shape, auth,
 * error vocabulary) is one class. If iOS ever needs a different provider
 * (fallback B), it is a second implementation of THIS interface and a binding
 * change; nothing above it moves.
 *
 * Contract: `send()` NEVER throws for provider/transport/config problems — it
 * returns a {@see PushSendResult} so the caller has exactly one place to branch.
 */
interface PushSender
{
    /**
     * @param  bool  $validateOnly  ask the provider to validate the request without delivering it
     *                              (credential smoke test; no device needed)
     */
    public function send(PushMessage $message, PushTarget $target, bool $validateOnly = false): PushSendResult;
}
