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
        'title' => 'Nouvelle invitation à une campagne',
        'body' => 'Invitation reçue : :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Révisions demandées',
        'body' => 'Modifications demandées sur votre brouillon : :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Brouillon approuvé',
        'body' => 'Votre brouillon a été approuvé : :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Brouillon refusé',
        'body' => 'Votre brouillon a été refusé : :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Campagne terminée',
        'body' => 'Terminée : :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Publication vérifiée',
        'body' => 'Votre publication a été vérifiée : :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Nouveau message',
        'body' => 'De :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Nouveau message',
        'body' => 'De :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Candidature acceptée',
        'body' => 'Acceptée par :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Nouvelle campagne disponible',
        'body' => 'Nouveau de :agency_name : :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Compte approuvé',
        'body' => 'Votre compte créateur a été approuvé. Ouvrez l\'application pour commencer.',
    ],
    'creator_rejected' => [
        'title' => 'Mise à jour de votre candidature',
        'body' => 'Ouvrez l\'application pour voir les détails.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Connecté à une agence',
        'body' => 'Connexion établie : :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Connexion à l\'agence terminée',
        'body' => 'Connexion terminée : :counterparty_name',
    ],
];
