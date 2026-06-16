<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Pozvani ste da se pridružite :agency na :app',
        'greeting' => 'Pozdrav, :name,',
        'body' => ':inviter vas je pozvao da se pridružite :agency kao :role. Ovaj poziv ističe za :days dana.',
        'cta' => 'Prihvati poziv',
        'ignore' => 'Ako niste očekivali ovaj poziv, možete sigurno zanemariti ovu e-poštu.',
    ],
    'roles' => [
        'agency_admin' => 'Administrator',
        'agency_manager' => 'Voditelj',
        'agency_staff' => 'Zaposlenik',
    ],
];
