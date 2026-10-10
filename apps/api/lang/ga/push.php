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
        'title' => 'Cuireadh nua chuig feachtas',
        'body' => 'Tugadh cuireadh duit: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Athruithe iarrtha',
        'body' => 'Iarradh athruithe ar do dhréacht: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Dréacht ceadaithe',
        'body' => 'Ceadaíodh do dhréacht: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Dréacht diúltaithe',
        'body' => 'Diúltaíodh do dhréacht: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Feachtas críochnaithe',
        'body' => 'Críochnaithe: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Postáil fíoraithe',
        'body' => 'Fíoraíodh do phostáil: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Teachtaireacht nua',
        'body' => 'Ó :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Teachtaireacht nua',
        'body' => 'Ó :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Iarratas glactha',
        'body' => 'Glactha ag :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Feachtas nua ar fáil',
        'body' => 'Nua ó :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Ceadaíodh thú',
        'body' => 'Ceadaíodh do chuntas cruthaitheora. Oscail an aip chun tús a chur leis.',
    ],
    'creator_rejected' => [
        'title' => 'Nuashonrú ar d\'iarratas',
        'body' => 'Oscail an aip chun na sonraí a fheiceáil.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Ceangailte le gníomhaireacht',
        'body' => 'Tá tú ceangailte anois: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Ceangal gníomhaireachta críochnaithe',
        'body' => 'Ceangal críochnaithe: :counterparty_name',
    ],
];
