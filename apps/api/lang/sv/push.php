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
        'title' => 'Ny kampanjinbjudan',
        'body' => 'Du har blivit inbjuden: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Ändringar begärda',
        'body' => 'Ändringar begärda i ditt utkast: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Utkast godkänt',
        'body' => 'Ditt utkast godkändes: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Utkast avvisat',
        'body' => 'Ditt utkast avvisades: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampanj slutförd',
        'body' => 'Slutförd: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Inlägg verifierat',
        'body' => 'Ditt inlägg har verifierats: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Nytt meddelande',
        'body' => 'Från :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Nytt meddelande',
        'body' => 'Från :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Ansökan godkänd',
        'body' => 'Godkänd av :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Ny kampanj tillgänglig',
        'body' => 'Nytt från :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Du är godkänd',
        'body' => 'Ditt kreatörskonto har godkänts. Öppna appen för att komma igång.',
    ],
    'creator_rejected' => [
        'title' => 'Uppdatering om din ansökan',
        'body' => 'Öppna appen för att se detaljerna.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Ansluten till en byrå',
        'body' => 'Du är nu ansluten: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Byråanslutning avslutad',
        'body' => 'Anslutning avslutad: :counterparty_name',
    ],
];
