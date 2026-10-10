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
        'title' => 'Nová pozvánka do kampane',
        'body' => 'Dostali ste pozvánku: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Vyžiadané úpravy',
        'body' => 'Vyžiadané zmeny vášho konceptu: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Koncept schválený',
        'body' => 'Váš koncept bol schválený: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Koncept zamietnutý',
        'body' => 'Váš koncept bol zamietnutý: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampaň dokončená',
        'body' => 'Dokončené: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Príspevok overený',
        'body' => 'Váš príspevok bol overený: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Nová správa',
        'body' => 'Od :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Nová správa',
        'body' => 'Od :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Žiadosť prijatá',
        'body' => 'Prijaté: :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'K dispozícii je nová kampaň',
        'body' => 'Nové od :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Boli ste schválení',
        'body' => 'Váš účet tvorcu bol schválený. Otvorte aplikáciu a začnite.',
    ],
    'creator_rejected' => [
        'title' => 'Aktualizácia vašej žiadosti',
        'body' => 'Podrobnosti nájdete v aplikácii.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Prepojené s agentúrou',
        'body' => 'Spojenie nadviazané: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Spojenie s agentúrou skončilo',
        'body' => 'Spojenie skončilo: :counterparty_name',
    ],
];
