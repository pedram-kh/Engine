<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Teid on kutsutud liituma :agency-ga rakenduses :app',
        'greeting' => 'Tere, :name,',
        'body' => ':inviter kutsus teid liituma :agency-ga rollina :role. See kutse aegub :days päeva pärast.',
        'cta' => 'Nõustu kutsega',
        'ignore' => 'Kui te seda kutset ei oodanud, võite selle e-kirja turvaliselt ignoreerida.',
    ],
    'roles' => [
        'agency_admin' => 'Administraator',
        'agency_manager' => 'Haldur',
        'agency_staff' => 'Töötaja',
    ],
];
