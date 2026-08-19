<?php

declare(strict_types=1);

return [
    // Sprint 9 Chunk 2 (D-14) — the assignment review/verification notification
    // set. Queued mailables, localized at queue time, rendered through the
    // shared `catalyst` markdown theme (mirrors creators.connection_request).
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Draft :n',
        'round_subject' => ':subject (:round)',
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
        // Verification-resolution chunk (D-8) — the creator-facing resolution mails.
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Your work for :campaign is complete',
                'greeting' => 'Hi :name,',
                'body' => 'Your draft for ":campaign" has been approved. On this campaign the agency publishes the content, so your assignment is now complete — there is nothing further for you to do.',
                'cta' => 'View the assignment',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Your post for :campaign was accepted',
                'greeting' => 'Hi :name,',
                'body' => 'Good news — the agency has reviewed and accepted your post for ":campaign". No further action is needed.',
                'cta' => 'View the assignment',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Action needed on your :campaign post',
                'greeting' => 'Hi :name,',
                'body_fresh' => 'The agency could not verify your post for ":campaign" and has asked you to submit a new post link. Open the assignment to resubmit.',
                'body_in_place' => 'The agency could not verify your post for ":campaign" and has asked you to fix the submitted link. Open the assignment to update it.',
                'feedback_label' => 'Note from the agency',
                'cta' => 'Open the assignment',
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
        // AH-083 (①) — a campaign offer is waiting. Fires on all three paths
        // that land an assignment on `invited`: the fresh invite, the AH-035
        // re-offer after a decline, and the agency's re-offer answering the
        // creator's own counter (kickoff Q4) — the latter two share the
        // `re_offer` copy, since the creator's experience is identical either
        // way.
        'invite_received' => [
            'email' => [
                'subject_fresh' => 'You have a new offer for :campaign',
                'subject_re_offer' => 'An updated offer for :campaign',
                'greeting' => 'Hi :name,',
                'body_fresh' => 'You\'ve been invited to work on ":campaign". Open the assignment to review the offer and respond.',
                'body_re_offer' => 'An updated offer is waiting for you on ":campaign". Open the assignment to review it and respond.',
                'cta' => 'View the offer',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency posted a new job',
        'greeting' => 'Hi :name,',
        'body' => ':agency has listed a new job on your board: ":campaign". Open it to see the details and apply.',
        'cta' => 'View the job',
        'ignore' => 'You are receiving this because you are on the :agency roster.',
    ],
    // AH-058 (Jobs Board chunk 4, D6) — the three application mails. All three
    // are queued and localized at queue time to the recipient's
    // preferred_language (a worker has no request locale), and all three are
    // gated by the `application_notifications_enabled` Pennant flag on the MAIL
    // leg only — the in-app rows write regardless.
    //
    // `rejected` carries TWO body variants selected by
    // ApplicationRejectionCause (`body_agency_rejected` / `body_campaign_closed`)
    // under ONE subject, the draft-reviewed `body_ . $outcome` precedent: the
    // recipient's question is the same either way, and two mailables would double
    // 24 locales of copy to express one sentence of difference.
    //
    // ⚠ No agency-supplied reason exists anywhere in the reject copy, by design
    // (D4): none is collected or stored, and the audit row plus its actor is the
    // internal record.
    'campaign_application' => [
        'submitted' => [
            'subject' => 'New application for :campaign',
            'greeting' => 'Hi :name,',
            'body' => ':creator applied to ":campaign". Open the campaign to review the application and send an offer.',
            'cta' => 'Review the application',
        ],
        'accepted' => [
            'subject' => 'Your application for :campaign was accepted',
            'greeting' => 'Hi :name,',
            'body' => ':agency accepted your application for ":campaign" and sent you an offer. Open the assignment to review the terms, then accept or decline.',
            'cta' => 'View the offer',
        ],
        'rejected' => [
            'subject' => 'An update on your application for :campaign',
            'greeting' => 'Hi :name,',
            'body_agency_rejected' => 'Thank you for applying to ":campaign". You were not selected for this job. New jobs are posted to your board regularly.',
            'body_campaign_closed' => 'Thank you for applying to ":campaign". The campaign has closed, so your application will not be taken forward. New jobs are posted to your board regularly.',
            'cta' => 'View your jobs board',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators still has a card in the posting column. Move that card out of the column before turning off creator posting.|[2,*] :count cards are still in the posting column (:creators). Move them out of the column before turning off creator posting.',
    ],
];
