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
        'title' => 'Нова покана за кампания',
        'body' => 'Поканени сте: :campaign_name',
    ],
    'assignment_revision_requested' => [
        'title' => 'Заявени корекции',
        'body' => 'Заявени промени по вашата чернова: :campaign_name',
    ],
    'assignment_draft_approved' => [
        'title' => 'Черновата е одобрена',
        'body' => 'Вашата чернова е одобрена: :campaign_name',
    ],
    'assignment_draft_rejected' => [
        'title' => 'Черновата е отхвърлена',
        'body' => 'Вашата чернова е отхвърлена: :campaign_name',
    ],
    'assignment_completed_on_approval' => [
        'title' => 'Кампанията е завършена',
        'body' => 'Завършена: :campaign_name',
    ],
    'assignment_manually_verified' => [
        'title' => 'Публикацията е потвърдена',
        'body' => 'Вашата публикация е потвърдена: :campaign_name',
    ],
    'message_received_by_creator' => [
        'title' => 'Ново съобщение',
        'body' => 'От :sender_name — :campaign_name',
    ],
    'message_relationship_received_by_creator' => [
        'title' => 'Ново съобщение',
        'body' => 'От :sender_name (:agency_name)',
    ],
    'campaign_application_accepted' => [
        'title' => 'Кандидатурата е приета',
        'body' => 'Приета от :agency_name — :campaign_name',
    ],
    'campaign_job_posted' => [
        'title' => 'Налична е нова кампания',
        'body' => 'Ново от :agency_name: :campaign_name',
    ],
    'creator_approved' => [
        'title' => 'Вие сте одобрени',
        'body' => 'Вашият акаунт на създател е одобрен. Отворете приложението, за да започнете.',
    ],
    'creator_rejected' => [
        'title' => 'Новини за вашата кандидатура',
        'body' => 'Отворете приложението за подробности.',
    ],
    'agency_creator_relation_admin_connected' => [
        'title' => 'Свързани сте с агенция',
        'body' => 'Връзката е установена: :agency_name',
    ],
    'agency_creator_relation_disconnected' => [
        'title' => 'Връзката с агенцията приключи',
        'body' => 'Връзката приключи: :counterparty_name',
    ],
];
