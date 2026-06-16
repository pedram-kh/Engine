<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Boli ste pozvaní k pripojeniu do :agency na :app',
        'greeting' => 'Dobrý deň, :name,',
        'body' => ':inviter vás pozval k pripojeniu do :agency ako :role. Táto pozvánka vyprší o :days dní.',
        'cta' => 'Prijať pozvánku',
        'ignore' => 'Ak ste túto pozvánku neočakávali, môžete tento e-mail bezpečne ignorovať.',
    ],
    'roles' => [
        'agency_admin' => 'Správca',
        'agency_manager' => 'Manažér',
        'agency_staff' => 'Zamestnanec',
    ],
];
