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
        'title' => 'Jauns uzaicinājums uz kampaņu',
        'body' => 'Saņemts uzaicinājums: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Pieprasīti labojumi',
        'body' => 'Pieprasītas izmaiņas jūsu melnrakstā: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Melnraksts apstiprināts',
        'body' => 'Jūsu melnraksts apstiprināts: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Melnraksts noraidīts',
        'body' => 'Jūsu melnraksts noraidīts: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampaņa pabeigta',
        'body' => 'Pabeigta: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Ieraksts verificēts',
        'body' => 'Jūsu ieraksts verificēts: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Jauna ziņa',
        'body' => 'No :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Jauna ziņa',
        'body' => 'No :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Pieteikums pieņemts',
        'body' => 'Pieņēma :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Pieejama jauna kampaņa',
        'body' => 'Jaunums no :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Jūs esat apstiprināts',
        'body' => 'Jūsu satura veidotāja konts ir apstiprināts. Atveriet lietotni, lai sāktu.',
    ],
    'creator_rejected' => [
        'title' => 'Jaunumi par jūsu pieteikumu',
        'body' => 'Atveriet lietotni, lai redzētu detaļas.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Savienots ar aģentūru',
        'body' => 'Savienojums izveidots: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Savienojums ar aģentūru beidzies',
        'body' => 'Savienojums beidzies: :counterparty_name',
    ],
];
