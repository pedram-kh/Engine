<?php

declare(strict_types=1);

return [
    'system' => [
        'assignment' => [
            'contracted' => 'Leping on allkirjastatud — tootmine võib alata.',
            'contracted_without_contract' => 'Tootmine võib alata.',
            'draft_submitted' => 'Mustand on ülevaatamiseks esitatud.',
            'draft_approved' => 'Mustand on kinnitatud.',
            'revision_requested' => 'Mustandi läbivaatamised on taotletud.',
            'draft_rejected' => 'Mustand on tagasi lükatud.',
            'posted_by_creator' => 'Looja on sisu märkinud avaldatuks.',
            'live_verified' => 'Otsepostitus on kontrollitud.',
            'manually_verified' => 'Postitus on käsitsi kontrollitud.',
            'resubmit_requested' => 'Uuesti esitamine on taotletud.',
            'payment_released' => 'Makse on väljastatud.',
        ],
    ],
    'digest' => [
        'subject' => 'Teil on lugemata sõnumeid',
        'greeting' => 'Tere, :name,',
        'intro' => 'Teil on :count lugemata sõnum(it) :threads vestluses(tes).',
        'cta' => 'Ava sõnumid',
        'thread_line' => ':campaign koos :counterparty — :count lugemata',
        'unknown_campaign' => 'kampaania',
        'unknown_counterparty' => 'keegi',
    ],

    'new_message' => [
        'subject_campaign' => 'Uus sõnum teemal :counterparty',
        'subject_relationship' => 'Uus sõnum saatjalt :counterparty',
        'greeting' => 'Tere, :name,',
        'body_campaign' => ':sender saatis teile uue sõnumi teemal ":counterparty".',
        'body_relationship' => ':sender ettevõttest :counterparty saatis teile uue sõnumi.',
        'cta' => 'Ava vestlus',
    ],
];
