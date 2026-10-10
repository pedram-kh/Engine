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
        'title' => 'Stedina ġdida għal kampanja',
        'body' => 'Irċevejt stedina: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Intalbu reviżjonijiet',
        'body' => 'Intalbu bidliet fid-abbozz tiegħek: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Abbozz approvat',
        'body' => 'L-abbozz tiegħek ġie approvat: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Abbozz miċħud',
        'body' => 'L-abbozz tiegħek ġie miċħud: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Kampanja kompluta',
        'body' => 'Komplut: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Post ivverifikat',
        'body' => 'Il-post tiegħek ġie vverifikat: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Messaġġ ġdid',
        'body' => 'Minn :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Messaġġ ġdid',
        'body' => 'Minn :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Applikazzjoni aċċettata',
        'body' => 'Aċċettata minn :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Kampanja ġdida disponibbli',
        'body' => 'Ġdid minn :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Int approvat',
        'body' => 'Il-kont tiegħek bħala kreatur ġie approvat. Iftaħ l-app biex tibda.',
    ],
    'creator_rejected' => [
        'title' => 'Aġġornament dwar l-applikazzjoni tiegħek',
        'body' => 'Iftaħ l-app biex tara d-dettalji.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Konness ma\' aġenzija',
        'body' => 'Ġejt konness: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Il-konnessjoni mal-aġenzija ntemmet',
        'body' => 'Konnessjoni mitmuma: :counterparty_name',
    ],
];
