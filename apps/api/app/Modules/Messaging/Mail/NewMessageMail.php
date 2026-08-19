<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Mail;

use App\Modules\Messaging\Services\DebouncedMessageMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * AH-083 (⑧) — the debounced "you have a new message" email, ONE class
 * serving BOTH thread models via a `context` discriminator (kickoff Q3, the
 * `DraftReviewedMail` precedent). The two contexts differ in exactly two
 * things — which counterparty name renders (the campaign name vs. the agency
 * name) and which URL builder runs ({@see self::buildThreadUrl()}; C5: the
 * relationship link uses the AGENCY's ulid, never the thread's own ulid, per
 * `apps/main/src/modules/creators/routes.ts`'s `:agencyUlid` route param) —
 * everything else (the queueing, the debounce gate) is shared.
 *
 * Queued ONLY by {@see DebouncedMessageMailer} — never constructed and queued
 * directly from a notifier, so the debounce gate and the flag check can never
 * be bypassed by a future call site.
 */
final class NewMessageMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'campaign'|'relationship'  $context
     * @param  string|null  $assignmentUlid  Required when $context is 'campaign'.
     * @param  string|null  $agencyUlid  Required when $context is 'relationship'.
     */
    public function __construct(
        public readonly string $recipientName,
        public readonly string $senderName,
        public readonly string $context,
        public readonly string $counterpartyName,
        public readonly ?string $assignmentUlid = null,
        public readonly ?string $agencyUlid = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) __('messages.new_message.subject_'.$this->context, ['counterparty' => $this->counterpartyName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.messaging.new-message',
            with: [
                'recipientName' => $this->recipientName,
                'senderName' => $this->senderName,
                'context' => $this->context,
                'counterpartyName' => $this->counterpartyName,
                'threadUrl' => $this->buildThreadUrl(),
            ],
        );
    }

    /**
     * The two link shapes are NOT symmetric (C5): the campaign thread renders
     * inline via `ChatPanel` on the assignment detail page (no anchor
     * needed), while the relationship thread is keyed by the AGENCY's ulid,
     * not `RelationshipThread::$ulid` — the creator-side route is
     * `/creator/messages/:agencyUlid`, and a naive "use the thread's own
     * ulid" helper would silently 404 every relationship-thread email.
     */
    private function buildThreadUrl(): string
    {
        $base = rtrim((string) config('app.frontend_main_url', 'http://127.0.0.1:5173'), '/');

        return match ($this->context) {
            'campaign' => $base.'/creator/assignments/'.$this->assignmentUlid,
            'relationship' => $base.'/creator/messages/'.$this->agencyUlid,
        };
    }
}
