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
        'title' => 'Novo vabilo v kampanjo',
        'body' => 'Prejeli ste vabilo: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Zahtevani popravki',
        'body' => 'Zahtevane spremembe osnutka: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Osnutek odobren',
        'body' => 'Vaš osnutek je odobren: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Osnutek zavrnjen',
        'body' => 'Vaš osnutek je zavrnjen: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampanja zaključena',
        'body' => 'Zaključeno: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Objava preverjena',
        'body' => 'Vaša objava je preverjena: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Novo sporočilo',
        'body' => 'Od :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Novo sporočilo',
        'body' => 'Od :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Prijava sprejeta',
        'body' => 'Sprejeto: :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Na voljo je nova kampanja',
        'body' => 'Novo od :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Odobreni ste',
        'body' => 'Vaš ustvarjalski račun je odobren. Odprite aplikacijo in začnite.',
    ],
    'creator_rejected' => [
        'title' => 'Novice o vaši prijavi',
        'body' => 'Odprite aplikacijo za podrobnosti.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Povezani z agencijo',
        'body' => 'Povezava vzpostavljena: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Povezava z agencijo končana',
        'body' => 'Povezava končana: :counterparty_name',
    ],
];
