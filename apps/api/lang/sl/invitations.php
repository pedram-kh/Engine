<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Povabljeni ste bili, da se pridružite :agency na :app',
        'greeting' => 'Pozdravljeni, :name,',
        'body' => ':inviter vas je povabil, da se pridružite :agency kot :role. To povabilo poteče čez :days dni.',
        'cta' => 'Sprejmi povabilo',
        'ignore' => 'Če tega povabila niste pričakovali, lahko to e-sporočilo varno prezrete.',
    ],
    'roles' => [
        'agency_admin' => 'Skrbnik',
        'agency_manager' => 'Vodja',
        'agency_staff' => 'Uslužbenec',
    ],
];
