<?php

declare(strict_types=1);

return [
    'system' => [
        'assignment' => [
            'contracted' => 'Sutartis pasirašyta — gamyba gali prasidėti.',
            'contracted_without_contract' => 'Gamyba gali prasidėti.',
            'draft_submitted' => 'Juodraštis pateiktas peržiūrai.',
            'draft_approved' => 'Juodraštis patvirtintas.',
            'revision_requested' => 'Paprašyta juodraščio pataisymų.',
            'draft_rejected' => 'Juodraštis atmestas.',
            'posted_by_creator' => 'Kūrėjas pažymėjo turinį kaip paskelbtą.',
            'live_verified' => 'Tiesioginis įrašas patikrintas.',
            'manually_verified' => 'Įrašas patikrintas rankiniu būdu.',
            'resubmit_requested' => 'Paprašyta pateikti iš naujo.',
            'payment_released' => 'Mokėjimas išleistas.',
        ],
    ],
    'digest' => [
        'subject' => 'Turite neperskaitytų žinučių',
        'greeting' => 'Sveiki, :name,',
        'intro' => 'Turite :count neperskaitytą/-ų žinutę/-čių :threads pokalbiuose.',
        'cta' => 'Atidaryti žinutes',
        'thread_line' => ':campaign su :counterparty — :count neskaitytas/-ų',
        'unknown_campaign' => 'kampanija',
        'unknown_counterparty' => 'kažkas',
    ],

    'new_message' => [
        'subject_campaign' => 'Naujas pranešimas apie :counterparty',
        'subject_relationship' => 'Naujas pranešimas nuo :counterparty',
        'greeting' => 'Sveiki, :name,',
        'body_campaign' => ':sender atsiuntė jums naują pranešimą apie ":counterparty".',
        'body_relationship' => ':sender iš :counterparty atsiuntė jums naują pranešimą.',
        'cta' => 'Atidaryti pokalbį',
    ],
];
