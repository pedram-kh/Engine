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
        'title' => 'Uus kampaaniakutse',
        'body' => 'Sind on kutsutud: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Paluti parandusi',
        'body' => 'Palutud muudatusi sinu mustandis: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Mustand kinnitatud',
        'body' => 'Sinu mustand kinnitati: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Mustand tagasi lükatud',
        'body' => 'Sinu mustand lükati tagasi: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampaania lõpetatud',
        'body' => 'Lõpetatud: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Postitus kinnitatud',
        'body' => 'Sinu postitus kinnitati: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Uus sõnum',
        'body' => 'Saatja :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Uus sõnum',
        'body' => 'Saatja :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Taotlus vastu võetud',
        'body' => 'Vastu võtnud :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Saadaval on uus kampaania',
        'body' => 'Uus: :agency_name — :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Sind on kinnitatud',
        'body' => 'Sinu loojakonto on kinnitatud. Alustamiseks ava rakendus.',
    ],
    'creator_rejected' => [
        'title' => 'Uudis sinu taotluse kohta',
        'body' => 'Üksikasjade nägemiseks ava rakendus.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Agentuuriga ühendatud',
        'body' => 'Ühendus loodud: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Agentuuriga ühendus lõppes',
        'body' => 'Ühendus lõppes: :counterparty_name',
    ],
];
