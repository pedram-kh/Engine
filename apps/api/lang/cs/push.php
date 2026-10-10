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
        'title' => 'Nová pozvánka do kampaně',
        'body' => 'Byli jste pozváni: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Požadovány úpravy',
        'body' => 'Požadovány změny vašeho konceptu: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Koncept schválen',
        'body' => 'Váš koncept byl schválen: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Koncept zamítnut',
        'body' => 'Váš koncept byl zamítnut: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampaň dokončena',
        'body' => 'Dokončeno: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Příspěvek ověřen',
        'body' => 'Váš příspěvek byl ověřen: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Nová zpráva',
        'body' => 'Od :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Nová zpráva',
        'body' => 'Od :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Žádost přijata',
        'body' => 'Přijato agenturou :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'K dispozici je nová kampaň',
        'body' => 'Nové od :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Jste schváleni',
        'body' => 'Váš účet tvůrce byl schválen. Otevřete aplikaci a začněte.',
    ],
    'creator_rejected' => [
        'title' => 'Aktualizace vaší žádosti',
        'body' => 'Podrobnosti najdete v aplikaci.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Propojeno s agenturou',
        'body' => 'Spojení navázáno: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Spojení s agenturou skončilo',
        'body' => 'Spojení skončilo: :counterparty_name',
    ],
];
