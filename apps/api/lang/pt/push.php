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
        'title' => 'Novo convite para uma campanha',
        'body' => 'Convite recebido: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Revisões solicitadas',
        'body' => 'Alterações solicitadas ao rascunho: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Rascunho aprovado',
        'body' => 'Rascunho aprovado: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Rascunho rejeitado',
        'body' => 'Rascunho rejeitado: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Campanha concluída',
        'body' => 'Concluída: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Publicação verificada',
        'body' => 'Publicação verificada: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Nova mensagem',
        'body' => 'De :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Nova mensagem',
        'body' => 'De :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Candidatura aprovada',
        'body' => 'Aprovada por :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Nova campanha disponível',
        'body' => 'Novidade de :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Conta aprovada',
        'body' => 'A sua conta de criador foi aprovada. Abra a app para começar.',
    ],
    'creator_rejected' => [
        'title' => 'Novidades sobre a sua candidatura',
        'body' => 'Abra a app para ver os detalhes.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Conectado a uma agência',
        'body' => 'Conexão estabelecida: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Conexão com a agência terminada',
        'body' => 'Conexão terminada: :counterparty_name',
    ],
];
