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
];
