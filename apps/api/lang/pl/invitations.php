<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Zostałeś zaproszony do dołączenia do :agency na :app',
        'greeting' => 'Cześć, :name,',
        'body' => ':inviter zaprosił Cię do dołączenia do :agency jako :role. To zaproszenie wygasa za :days dni.',
        'cta' => 'Zaakceptuj zaproszenie',
        'ignore' => 'Jeśli nie spodziewałeś się tego zaproszenia, możesz bezpiecznie zignorować tę wiadomość.',
    ],
    'roles' => [
        'agency_admin' => 'Administrator',
        'agency_manager' => 'Menedżer',
        'agency_staff' => 'Pracownik',
    ],
];
