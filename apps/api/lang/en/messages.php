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
    ],
];
