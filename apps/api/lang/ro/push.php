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
        'title' => 'Invitație nouă la o campanie',
        'body' => 'Ai primit o invitație: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Revizuiri solicitate',
        'body' => 'Modificări solicitate la ciorna ta: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Ciornă aprobată',
        'body' => 'Ciorna ta a fost aprobată: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Ciornă respinsă',
        'body' => 'Ciorna ta a fost respinsă: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Campanie finalizată',
        'body' => 'Finalizată: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Postare verificată',
        'body' => 'Postarea ta a fost verificată: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Mesaj nou',
        'body' => 'De la :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Mesaj nou',
        'body' => 'De la :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Candidatură acceptată',
        'body' => 'Acceptată de :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Campanie nouă disponibilă',
        'body' => 'Nou de la :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Ești aprobat',
        'body' => 'Contul tău de creator a fost aprobat. Deschide aplicația pentru a începe.',
    ],
    'creator_rejected' => [
        'title' => 'Noutăți despre candidatura ta',
        'body' => 'Deschide aplicația pentru detalii.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Conectat la o agenție',
        'body' => 'Conexiune stabilită: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Conexiunea cu agenția s-a încheiat',
        'body' => 'Conexiune încheiată: :counterparty_name',
    ],
];
