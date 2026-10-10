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
        'title' => 'Neue Kampagneneinladung',
        'body' => 'Du wurdest eingeladen: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Überarbeitung angefordert',
        'body' => 'Änderungen an deinem Entwurf angefordert: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Entwurf genehmigt',
        'body' => 'Dein Entwurf wurde genehmigt: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Entwurf abgelehnt',
        'body' => 'Dein Entwurf wurde abgelehnt: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampagne abgeschlossen',
        'body' => 'Abgeschlossen: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Beitrag verifiziert',
        'body' => 'Dein Beitrag wurde verifiziert: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Neue Nachricht',
        'body' => 'Von :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Neue Nachricht',
        'body' => 'Von :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Bewerbung angenommen',
        'body' => 'Angenommen von :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Neue Kampagne verfügbar',
        'body' => 'Neu von :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Du bist freigeschaltet',
        'body' => 'Dein Creator-Konto wurde genehmigt. Öffne die App, um loszulegen.',
    ],
    'creator_rejected' => [
        'title' => 'Neuigkeiten zu deiner Bewerbung',
        'body' => 'Öffne die App, um die Details zu sehen.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Mit einer Agentur verbunden',
        'body' => 'Du bist jetzt verbunden: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Agenturverbindung beendet',
        'body' => 'Verbindung beendet: :counterparty_name',
    ],
];
