<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Jūs esat uzaicināts pievienoties :agency platformā :app',
        'greeting' => 'Sveiki, :name,',
        'body' => ':inviter uzaicināja jūs pievienoties :agency kā :role. Šis uzaicinājums beidzas pēc :days dienām.',
        'cta' => 'Pieņemt uzaicinājumu',
        'ignore' => 'Ja negaidījāt šo uzaicinājumu, varat droši ignorēt šo e-pastu.',
    ],
    'roles' => [
        'agency_admin' => 'Administrators',
        'agency_manager' => 'Vadītājs',
        'agency_staff' => 'Darbinieks',
    ],
];
