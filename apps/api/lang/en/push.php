<?php

declare(strict_types=1);

// AH-102 — mobile push copy for the creator app (14 eligible notification types).
//
// Keyed by the notification type's dotted value with `.` → `_`. Each type has a
// fixed `title` and an optional `body`. Privacy contract (Q6): NO message text,
// feedback, or rejection reason ever appears here or can reach a payload — the
// only dynamic parts are the allowlisted NAME placeholders (:campaign_name,
// :agency_name, :counterparty_name, :sender_name), injected untranslated and
// placed after a colon/dash/parenthesis so no inflection of a name is needed.
// If a body's name is missing at send time the body is dropped and the title
// alone is sent.
return [
    'assignment_invited' => [
        'title' => 'New campaign invitation',
        'body' => 'You\'ve been invited: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Revisions requested',
        'body' => 'Changes requested on your draft: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Draft approved',
        'body' => 'Your draft was approved: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Draft rejected',
        'body' => 'Your draft was rejected: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Campaign completed',
        'body' => 'Completed: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Post verified',
        'body' => 'Your post was verified: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'New message',
        'body' => 'From :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'New message',
        'body' => 'From :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Application accepted',
        'body' => 'Accepted by :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'New campaign available',
        'body' => 'New from :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'You\'re approved',
        'body' => 'Your creator account was approved. Open the app to get started.',
    ],
    'creator_rejected' => [
        'title' => 'Update on your application',
        'body' => 'Open the app to see the details.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Connected to an agency',
        'body' => 'You\'re now connected: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Agency connection ended',
        'body' => 'Connection ended: :counterparty_name',
    ],
];
