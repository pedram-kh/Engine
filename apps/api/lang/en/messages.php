<?php

declare(strict_types=1);

return [
    // Sprint 11 (D-5) — the localized lines for system messages, keyed by the
    // AuditAction verb string stored on `messages.system_event_key`. Rendered at
    // display time (the daily digest email here; the SPA renders its own copy
    // from app.messaging.system.* client-side). Never stored as text.
    'system' => [
        'assignment' => [
            'contracted' => 'The contract was signed — production can begin.',
            'contracted_without_contract' => 'Production can begin.',
            'draft_submitted' => 'A draft was submitted for review.',
            'draft_approved' => 'The draft was approved.',
            'revision_requested' => 'Revisions were requested on the draft.',
            'draft_rejected' => 'The draft was rejected.',
            'posted_by_creator' => 'The creator marked the content as posted.',
            'live_verified' => 'The live post was verified.',
            'manually_verified' => 'The post was manually verified.',
            'resubmit_requested' => 'A resubmission was requested.',
            'payment_released' => 'Payment was released.',
        ],
    ],

    // The daily unread-messages digest email (D-9). One aggregated email per
    // opted-in user with unread messages.
    'digest' => [
        'subject' => 'You have unread messages',
        'greeting' => 'Hi :name,',
        'intro' => 'You have :count unread message(s) across :threads conversation(s).',
        'cta' => 'Open your messages',
        'thread_line' => ':campaign with :counterparty — :count unread',
        'unknown_campaign' => 'a campaign',
        'unknown_counterparty' => 'someone',
    ],

    // AH-083 (⑧) — the debounced immediate-message email, one class shared by
    // BOTH thread models via a `context`-keyed sub-structure (`campaign` /
    // `relationship`), mirroring `campaigns.php`'s `reviewed.email` shape.
    'new_message' => [
        'subject_campaign' => 'New message about :counterparty',
        'subject_relationship' => 'New message from :counterparty',
        'greeting' => 'Hi :name,',
        'body_campaign' => ':sender sent you a new message about ":counterparty".',
        'body_relationship' => ':sender at :counterparty sent you a new message.',
        'cta' => 'Open the conversation',
    ],
];
