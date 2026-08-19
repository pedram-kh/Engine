<?php

declare(strict_types=1);

return [
    'system' => [
        'assignment' => [
            'contracted' => 'Kontrakten er underskrevet — produktionen kan begynde.',
            'contracted_without_contract' => 'Produktionen kan begynde.',
            'draft_submitted' => 'Et udkast er indsendt til gennemgang.',
            'draft_approved' => 'Udkastet er godkendt.',
            'revision_requested' => 'Der er anmodet om ændringer til udkastet.',
            'draft_rejected' => 'Udkastet er afvist.',
            'posted_by_creator' => 'Creatoren har markeret indholdet som publiceret.',
            'live_verified' => 'Det live opslag er verificeret.',
            'manually_verified' => 'Opslaget er manuelt verificeret.',
            'resubmit_requested' => 'Der er anmodet om genindsendelse.',
            'payment_released' => 'Betalingen er frigivet.',
        ],
    ],

    'digest' => [
        'subject' => 'Du har ulæste beskeder',
        'greeting' => 'Hej :name,',
        'intro' => 'Du har :count ulæst(e) besked(er) i :threads samtale(r).',
        'cta' => 'Åbn beskeder',
        'thread_line' => ':campaign med :counterparty — :count ulæste',
        'unknown_campaign' => 'en kampagne',
        'unknown_counterparty' => 'nogen',
    ],

    'new_message' => [
        'subject_campaign' => 'Ny besked om :counterparty',
        'subject_relationship' => 'Ny besked fra :counterparty',
        'greeting' => 'Hej :name,',
        'body_campaign' => ':sender har sendt dig en ny besked om ":counterparty".',
        'body_relationship' => ':sender hos :counterparty har sendt dig en ny besked.',
        'cta' => 'Åbn samtalen',
    ],
];
