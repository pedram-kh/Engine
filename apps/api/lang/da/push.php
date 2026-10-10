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
        'title' => 'Ny kampagneinvitation',
        'body' => 'Du er inviteret: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Rettelser ønskes',
        'body' => 'Ændringer ønskes i dit udkast: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Udkast godkendt',
        'body' => 'Dit udkast blev godkendt: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Udkast afvist',
        'body' => 'Dit udkast blev afvist: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampagne afsluttet',
        'body' => 'Afsluttet: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Opslag bekræftet',
        'body' => 'Dit opslag er bekræftet: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Ny besked',
        'body' => 'Fra :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Ny besked',
        'body' => 'Fra :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Ansøgning accepteret',
        'body' => 'Accepteret af :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Ny kampagne tilgængelig',
        'body' => 'Nyt fra :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Du er godkendt',
        'body' => 'Din creator-konto er godkendt. Åbn appen for at komme i gang.',
    ],
    'creator_rejected' => [
        'title' => 'Opdatering om din ansøgning',
        'body' => 'Åbn appen for at se detaljerne.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Forbundet med et bureau',
        'body' => 'Du er nu forbundet: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Bureauforbindelse afsluttet',
        'body' => 'Forbindelsen er afsluttet: :counterparty_name',
    ],
];
