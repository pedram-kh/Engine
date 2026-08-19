<?php

declare(strict_types=1);

return [
    'system' => [
        'assignment' => [
            'contracted' => 'Pogodba je bila podpisana — produkcija se lahko začne.',
            'contracted_without_contract' => 'Produkcija se lahko začne.',
            'draft_submitted' => 'Oddan je bil osnutek v pregled.',
            'draft_approved' => 'Osnutek je bil odobren.',
            'revision_requested' => 'Zahtevane so bile revizije osnutka.',
            'draft_rejected' => 'Osnutek je bil zavrnjen.',
            'posted_by_creator' => 'Ustvarjalec je označil vsebino kot objavljeno.',
            'live_verified' => 'Živá objava je bila preverjena.',
            'manually_verified' => 'Objava je bila ročno preverjena.',
            'resubmit_requested' => 'Zahtevana je bila ponovna oddaja.',
            'payment_released' => 'Plačilo je bilo sproščeno.',
        ],
    ],

    'digest' => [
        'subject' => 'Imate neprebrana sporočila',
        'greeting' => 'Pozdravljeni, :name,',
        'intro' => 'Imate :count neprebrano/a sporočilo/a v :threads pogovorih.',
        'cta' => 'Odpri sporočila',
        'thread_line' => ':campaign z :counterparty — :count neprebrano/a',
        'unknown_campaign' => 'kampanja',
        'unknown_counterparty' => 'nekdo',
    ],

    'new_message' => [
        'subject_campaign' => 'Novo sporočilo o :counterparty',
        'subject_relationship' => 'Novo sporočilo od :counterparty',
        'greeting' => 'Pozdravljeni, :name,',
        'body_campaign' => ':sender vam je poslal(a) novo sporočilo o ":counterparty".',
        'body_relationship' => ':sender iz :counterparty vam je poslal(a) novo sporočilo.',
        'cta' => 'Odpri pogovor',
    ],
];
