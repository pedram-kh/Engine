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
        'title' => 'Nieuwe campagne-uitnodiging',
        'body' => 'Je bent uitgenodigd: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Aanpassingen gevraagd',
        'body' => 'Wijzigingen gevraagd voor je concept: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Concept goedgekeurd',
        'body' => 'Je concept is goedgekeurd: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Concept afgewezen',
        'body' => 'Je concept is afgewezen: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Campagne afgerond',
        'body' => 'Afgerond: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Post geverifieerd',
        'body' => 'Je post is geverifieerd: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Nieuw bericht',
        'body' => 'Van :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Nieuw bericht',
        'body' => 'Van :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Aanvraag geaccepteerd',
        'body' => 'Geaccepteerd door :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Nieuwe campagne beschikbaar',
        'body' => 'Nieuw van :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Je bent goedgekeurd',
        'body' => 'Je creatoraccount is goedgekeurd. Open de app om te beginnen.',
    ],
    'creator_rejected' => [
        'title' => 'Update over je aanvraag',
        'body' => 'Open de app voor de details.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Verbonden met een bureau',
        'body' => 'Je bent nu verbonden: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Bureauverbinding beëindigd',
        'body' => 'Verbinding beëindigd: :counterparty_name',
    ],
];
