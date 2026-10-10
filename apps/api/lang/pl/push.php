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
        'title' => 'Nowe zaproszenie do kampanii',
        'body' => 'Masz zaproszenie: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Poproszono o poprawki',
        'body' => 'Poproszono o zmiany w szkicu: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Szkic zatwierdzony',
        'body' => 'Twój szkic został zatwierdzony: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Szkic odrzucony',
        'body' => 'Twój szkic został odrzucony: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampania zakończona',
        'body' => 'Zakończona: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Post zweryfikowany',
        'body' => 'Twój post został zweryfikowany: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Nowa wiadomość',
        'body' => 'Od :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Nowa wiadomość',
        'body' => 'Od :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Zgłoszenie zaakceptowane',
        'body' => 'Zaakceptowane przez :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Dostępna nowa kampania',
        'body' => 'Nowość od :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Konto zatwierdzone',
        'body' => 'Twoje konto twórcy zostało zatwierdzone. Otwórz aplikację, aby zacząć.',
    ],
    'creator_rejected' => [
        'title' => 'Aktualizacja dotycząca zgłoszenia',
        'body' => 'Otwórz aplikację, aby zobaczyć szczegóły.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Połączono z agencją',
        'body' => 'Połączenie nawiązane: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Połączenie z agencją zakończone',
        'body' => 'Połączenie zakończone: :counterparty_name',
    ],
];
