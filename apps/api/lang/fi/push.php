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
        'title' => 'Uusi kampanjakutsu',
        'body' => 'Sinut on kutsuttu: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Muutoksia pyydetty',
        'body' => 'Luonnokseesi pyydettiin muutoksia: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Luonnos hyväksytty',
        'body' => 'Luonnoksesi hyväksyttiin: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Luonnos hylätty',
        'body' => 'Luonnoksesi hylättiin: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampanja valmis',
        'body' => 'Valmis: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Julkaisu vahvistettu',
        'body' => 'Julkaisusi vahvistettiin: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Uusi viesti',
        'body' => 'Lähettäjä :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Uusi viesti',
        'body' => 'Lähettäjä :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Hakemus hyväksytty',
        'body' => 'Hyväksyjä :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Uusi kampanja saatavilla',
        'body' => 'Uutta: :agency_name — :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Sinut on hyväksytty',
        'body' => 'Tekijätilisi on hyväksytty. Aloita avaamalla sovellus.',
    ],
    'creator_rejected' => [
        'title' => 'Päivitys hakemukseesi',
        'body' => 'Avaa sovellus nähdäksesi tiedot.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Yhdistetty toimistoon',
        'body' => 'Yhteys muodostettu: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Toimistoyhteys päättyi',
        'body' => 'Yhteys päättyi: :counterparty_name',
    ],
];
