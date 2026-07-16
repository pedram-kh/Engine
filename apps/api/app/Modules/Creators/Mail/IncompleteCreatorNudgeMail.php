<?php

declare(strict_types=1);

namespace App\Modules\Creators\Mail;

use App\Modules\Creators\Enums\IncompleteCreatorNudgeVariant;
use App\Modules\Creators\Services\IncompleteCreatorNudgeService;
use App\Modules\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one-time incomplete-creator nudge email (D4). One mailable, two variants
 * (verify / finish) selected by the recipient's `email_verified_at` upstream in
 * {@see IncompleteCreatorNudgeService}.
 *
 * Subject + body are localized to the recipient's `preferred_language` via
 * Laravel's mailable `locale()` helper at queue time (the verification-mail
 * pattern, NOT the digest's English-only shape). Rendered through the shared
 * `catalyst` markdown theme (config/mail.php).
 *
 * `tags: ['creators', 'onboarding-nudge']` keeps this out of any future
 * marketing stream (the transactional posture — see the review file, D5).
 *
 * Verify variant: `actionUrl` is a fresh signed verify-email link
 * ({frontend_main_url}/auth/verify-email?token=…) + `expiresInHours` for the
 * copy. Finish variant: `actionUrl` is {frontend_main_url}/onboarding and
 * `expiresInHours` is null.
 */
final class IncompleteCreatorNudgeMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public IncompleteCreatorNudgeVariant $variant,
        public string $actionUrl,
        public ?int $expiresInHours = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('creators.incomplete_nudge.'.$this->variant->value.'.subject'),
            tags: ['creators', 'onboarding-nudge'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.creators.incomplete-nudge-'.$this->variant->value,
            with: [
                'user' => $this->user,
                'actionUrl' => $this->actionUrl,
                'expiresInHours' => $this->expiresInHours,
                'appName' => config('app.name'),
            ],
        );
    }
}
