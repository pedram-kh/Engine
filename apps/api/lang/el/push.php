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
        'title' => 'Νέα πρόσκληση σε καμπάνια',
        'body' => 'Λάβατε πρόσκληση: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Ζητήθηκαν αναθεωρήσεις',
        'body' => 'Ζητήθηκαν αλλαγές στο προσχέδιό σας: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Το προσχέδιο εγκρίθηκε',
        'body' => 'Το προσχέδιό σας εγκρίθηκε: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Το προσχέδιο απορρίφθηκε',
        'body' => 'Το προσχέδιό σας απορρίφθηκε: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Η καμπάνια ολοκληρώθηκε',
        'body' => 'Ολοκληρώθηκε: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Η ανάρτηση επαληθεύτηκε',
        'body' => 'Η ανάρτησή σας επαληθεύτηκε: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Νέο μήνυμα',
        'body' => 'Από :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Νέο μήνυμα',
        'body' => 'Από :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Η αίτηση έγινε δεκτή',
        'body' => 'Έγινε δεκτή από :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Νέα διαθέσιμη καμπάνια',
        'body' => 'Νέο από :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Εγκριθήκατε',
        'body' => 'Ο λογαριασμός δημιουργού σας εγκρίθηκε. Ανοίξτε την εφαρμογή για να ξεκινήσετε.',
    ],
    'creator_rejected' => [
        'title' => 'Ενημέρωση για την αίτησή σας',
        'body' => 'Ανοίξτε την εφαρμογή για λεπτομέρειες.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Συνδεθήκατε με πρακτορείο',
        'body' => 'Η σύνδεση ολοκληρώθηκε: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Η σύνδεση με το πρακτορείο έληξε',
        'body' => 'Η σύνδεση έληξε: :counterparty_name',
    ],
];
