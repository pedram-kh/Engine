<?php

declare(strict_types=1);

return [
    // Sprint 9 Chunk 2 (D-14) — the assignment review/verification notification
    // set. Queued mailables, localized at queue time, rendered through the
    // shared `catalyst` markdown theme (mirrors creators.connection_request).
    'assignment_notifications' => [
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator submitted a draft for review',
                'greeting' => 'Hi :name,',
                'body' => ':creator has submitted a draft for ":campaign". Open the campaign to approve it, request changes, or reject it.',
                'cta' => 'Review the draft',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Your draft for :campaign was approved',
                'subject_revision_requested' => 'Changes requested on your :campaign draft',
                'subject_rejected' => 'An update on your :campaign draft',
                'greeting' => 'Hi :name,',
                'body_approved' => 'Good news — your draft for ":campaign" was approved. You can now post it and submit the live link.',
                'body_revision_requested' => 'The agency has requested changes to your draft for ":campaign". Review the feedback below and resubmit.',
                'body_rejected' => 'After review, your draft for ":campaign" was not accepted and the assignment has been closed.',
                'feedback_label' => 'Feedback',
                'cta' => 'View the assignment',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Could not verify the post for :campaign',
                'greeting' => 'Hi :name,',
                'body' => 'We could not automatically verify :creator\'s post for ":campaign". Please review the submitted link.',
                'reason_label' => 'What happened',
                'reason_not_found' => 'The post could not be found at the submitted link.',
                'reason_mismatch' => 'The post at the submitted link does not appear to belong to the creator\'s connected account.',
                'cta' => 'Review the assignment',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Contract ready for :campaign',
                'greeting' => 'Hi :name,',
                'body' => 'A contract for ":campaign" is ready for your review. Open the assignment to read the terms and accept.',
                'cta' => 'Review the contract',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator accepted the contract',
                'greeting' => 'Hi :name,',
                'body' => ':creator has accepted the contract for ":campaign". They can now begin work on their draft.',
                'cta' => 'View the campaign',
            ],
        ],
    ],
];
