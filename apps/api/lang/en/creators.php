<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'You\'ve been invited to :agency on Catalyst',
            'greeting' => 'Hi,',
            'body' => ':agency has invited you to join their roster on Catalyst. Click the button below to set up your creator profile.',
            'cta' => 'Get started',
            'expiry' => 'This invitation expires on :date.',
            'ignore' => 'If you did not expect this invitation, you can safely ignore this email.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Your Catalyst application has been approved',
            'greeting' => 'Hi :name,',
            'body' => 'Good news — your creator application has been approved. You now have full access to your Catalyst dashboard.',
            'cta' => 'Go to your dashboard',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'An update on your Catalyst application',
            'greeting' => 'Hi :name,',
            'body' => 'Thank you for applying to Catalyst. After review, we\'re unable to approve your application at this time.',
            'reason_label' => 'Reason',
            'resubmit_hint' => 'You can update your application and resubmit it for another review from your dashboard.',
            'cta' => 'Review your application',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency wants to connect with you on Catalyst',
            'greeting' => 'Hi :name,',
            'body' => ':agency would like to add you to their roster on Catalyst. Open your dashboard to accept or decline the request.',
            'cta' => 'View the request',
            'ignore' => 'If you don\'t recognise this agency, you can simply decline — nothing changes until you accept.',
        ],
    ],
    // Incomplete-creator email nudge (scheduled, once-only). Transactional
    // tone — "finish the registration you started" — GDPR Contract basis, no
    // promotional language (see docs/reviews/incomplete-creator-nudge-review.md).
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Finish setting up your Catalyst account',
            'greeting' => 'Hi :name,',
            'body' => 'You started creating your Catalyst creator account but haven\'t confirmed your email address yet. Confirm it now to pick up where you left off and finish your registration.',
            'cta' => 'Confirm your email',
            'expiry' => 'This link expires in :hours hours. If it lapses, you can request a new one from the sign-in page.',
            'ignore' => 'If you didn\'t start this registration, you can safely ignore this email.',
        ],
        'finish' => [
            'subject' => 'Finish setting up your Catalyst creator profile',
            'greeting' => 'Hi :name,',
            'body' => 'You started setting up your Catalyst creator profile but haven\'t finished yet. Pick up where you left off to complete your registration.',
            'cta' => 'Finish your profile',
            'ignore' => 'If you\'ve already finished your profile, you can safely ignore this email.',
        ],
    ],
    // Sprint 7 (D-4). Generic by design — no reason, no scope/type detail.
    'blacklisted' => [
        'email' => [
            'subject' => ':agency has updated your collaboration status on Catalyst',
            'greeting' => 'Hi :name,',
            'body' => ':agency has updated your collaboration status on Catalyst. If you have any questions, please reach out to them directly.',
            'closing' => 'Thank you for being part of Catalyst.',
        ],
    ],
];
