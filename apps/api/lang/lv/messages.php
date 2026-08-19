<?php

declare(strict_types=1);

return [
    'system' => [
        'assignment' => [
            'contracted' => 'Līgums ir parakstīts — ražošana var sākties.',
            'contracted_without_contract' => 'Ražošana var sākties.',
            'draft_submitted' => 'Melnraksts ir iesniegts pārskatīšanai.',
            'draft_approved' => 'Melnraksts ir apstiprināts.',
            'revision_requested' => 'Pieprasītas melnraksta pārskatīšanas.',
            'draft_rejected' => 'Melnraksts ir noraidīts.',
            'posted_by_creator' => 'Radītājs ir atzīmējis saturu kā publicētu.',
            'live_verified' => 'Tiešraides ieraksts ir verificēts.',
            'manually_verified' => 'Ieraksts ir manuāli verificēts.',
            'resubmit_requested' => 'Pieprasīta atkārtota iesniegšana.',
            'payment_released' => 'Maksājums ir atbrīvots.',
        ],
    ],
    'digest' => [
        'subject' => 'Jums ir nelasīti ziņojumi',
        'greeting' => 'Sveiki, :name,',
        'intro' => 'Jums ir :count nelasīts/-i ziņojums/-i :threads sarunās.',
        'cta' => 'Atvērt ziņojumus',
        'thread_line' => ':campaign ar :counterparty — :count nelasīts/-i',
        'unknown_campaign' => 'kampaņa',
        'unknown_counterparty' => 'kāds',
    ],

    'new_message' => [
        'subject_campaign' => 'Jauns ziņojums par :counterparty',
        'subject_relationship' => 'Jauns ziņojums no :counterparty',
        'greeting' => 'Sveiki, :name,',
        'body_campaign' => ':sender jums nosūtīja jaunu ziņojumu par ":counterparty".',
        'body_relationship' => ':sender no :counterparty jums nosūtīja jaunu ziņojumu.',
        'cta' => 'Atvērt sarunu',
    ],
];
