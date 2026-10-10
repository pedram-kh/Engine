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
        'title' => 'Nova pozivnica za kampanju',
        'body' => 'Imate pozivnicu: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Zatražene izmjene',
        'body' => 'Zatražene izmjene vašeg nacrta: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Nacrt odobren',
        'body' => 'Vaš nacrt je odobren: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Nacrt odbijen',
        'body' => 'Vaš nacrt je odbijen: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampanja završena',
        'body' => 'Završeno: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Objava potvrđena',
        'body' => 'Vaša objava je potvrđena: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Nova poruka',
        'body' => 'Od :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Nova poruka',
        'body' => 'Od :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Prijava prihvaćena',
        'body' => 'Prihvaćeno: :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Dostupna nova kampanja',
        'body' => 'Novo od :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Odobreni ste',
        'body' => 'Vaš račun kreatora je odobren. Otvorite aplikaciju za početak.',
    ],
    'creator_rejected' => [
        'title' => 'Novosti o vašoj prijavi',
        'body' => 'Otvorite aplikaciju za detalje.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Povezani s agencijom',
        'body' => 'Veza uspostavljena: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Veza s agencijom završena',
        'body' => 'Veza završena: :counterparty_name',
    ],
];
