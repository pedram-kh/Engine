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
        'title' => 'Nuovo invito a una campagna',
        'body' => 'Hai ricevuto un invito: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Revisioni richieste',
        'body' => 'Modifiche richieste alla tua bozza: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Bozza approvata',
        'body' => 'La tua bozza è stata approvata: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Bozza rifiutata',
        'body' => 'La tua bozza è stata rifiutata: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Campagna completata',
        'body' => 'Completata: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Post verificato',
        'body' => 'Il tuo post è stato verificato: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Nuovo messaggio',
        'body' => 'Da :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Nuovo messaggio',
        'body' => 'Da :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Candidatura accettata',
        'body' => 'Accettata da :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Nuova campagna disponibile',
        'body' => 'Novità da :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Account approvato',
        'body' => 'Il tuo account creator è stato approvato. Apri l\'app per iniziare.',
    ],
    'creator_rejected' => [
        'title' => 'Aggiornamento sulla tua candidatura',
        'body' => 'Apri l\'app per vedere i dettagli.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Collegato a un\'agenzia',
        'body' => 'Ora sei collegato: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Collegamento con l\'agenzia terminato',
        'body' => 'Collegamento terminato: :counterparty_name',
    ],
];
