<?php

declare(strict_types=1);

return [
    'system' => [
        'assignment' => [
            'contracted' => 'Договорът е подписан — производството може да започне.',
            'draft_submitted' => 'Черновата е изпратена за преглед.',
            'draft_approved' => 'Черновата е одобрена.',
            'revision_requested' => 'Поискани са ревизии на черновата.',
            'draft_rejected' => 'Черновата е отхвърлена.',
            'posted_by_creator' => 'Творецът е отбелязал съдържанието като публикувано.',
            'live_verified' => 'Живата публикация е верифицирана.',
            'manually_verified' => 'Публикацията е верифицирана ръчно.',
            'resubmit_requested' => 'Поискано е повторно изпращане.',
            'payment_released' => 'Плащането е освободено.',
        ],
    ],

    'digest' => [
        'subject' => 'Имате непрочетени съобщения',
        'greeting' => 'Здравей, :name,',
        'intro' => 'Имате :count непрочетено/и съобщение/я в :threads разговора/и.',
        'cta' => 'Отвори съобщенията',
        'thread_line' => ':campaign с :counterparty — :count непрочетено/и',
        'unknown_campaign' => 'кампания',
        'unknown_counterparty' => 'някой',
    ],
];
