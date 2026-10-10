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
        'title' => 'Új kampánymeghívó',
        'body' => 'Meghívást kaptál: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Módosítást kértek',
        'body' => 'Módosítást kértek a vázlatodhoz: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Vázlat jóváhagyva',
        'body' => 'A vázlatodat jóváhagyták: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Vázlat elutasítva',
        'body' => 'A vázlatodat elutasították: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampány lezárva',
        'body' => 'Lezárva: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Bejegyzés ellenőrizve',
        'body' => 'A bejegyzésedet ellenőrizték: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Új üzenet',
        'body' => 'Feladó: :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Új üzenet',
        'body' => 'Feladó: :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Jelentkezés elfogadva',
        'body' => 'Elfogadta: :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Új kampány érhető el',
        'body' => 'Új tőle: :agency_name — :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Jóváhagyták a fiókodat',
        'body' => 'Alkotói fiókodat jóváhagyták. A kezdéshez nyisd meg az appot.',
    ],
    'creator_rejected' => [
        'title' => 'Frissítés a jelentkezésedről',
        'body' => 'A részletekért nyisd meg az appot.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Ügynökséghez kapcsolva',
        'body' => 'Kapcsolat létrejött: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Az ügynökségi kapcsolat megszűnt',
        'body' => 'Kapcsolat megszűnt: :counterparty_name',
    ],
];
