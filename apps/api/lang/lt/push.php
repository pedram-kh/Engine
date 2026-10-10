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
        'title' => 'Naujas kvietimas į kampaniją',
        'body' => 'Gavote kvietimą: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Paprašyta pataisymų',
        'body' => 'Paprašyta pakeisti jūsų juodraštį: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Juodraštis patvirtintas',
        'body' => 'Jūsų juodraštis patvirtintas: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Juodraštis atmestas',
        'body' => 'Jūsų juodraštis atmestas: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampanija baigta',
        'body' => 'Baigta: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Įrašas patvirtintas',
        'body' => 'Jūsų įrašas patvirtintas: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Nauja žinutė',
        'body' => 'Nuo :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Nauja žinutė',
        'body' => 'Nuo :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Paraiška priimta',
        'body' => 'Priėmė :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Prieinama nauja kampanija',
        'body' => 'Nauja: :agency_name — :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Jūs patvirtinti',
        'body' => 'Jūsų kūrėjo paskyra patvirtinta. Atidarykite programėlę, kad pradėtumėte.',
    ],
    'creator_rejected' => [
        'title' => 'Naujienos apie jūsų paraišką',
        'body' => 'Atidarykite programėlę, kad pamatytumėte informaciją.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Prisijungta prie agentūros',
        'body' => 'Ryšys užmegztas: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Ryšys su agentūra baigtas',
        'body' => 'Ryšys baigtas: :counterparty_name',
    ],
];
