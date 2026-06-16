<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Byli jste pozváni k připojení do :agency na :app',
        'greeting' => 'Dobrý den, :name,',
        'body' => ':inviter vás pozval k připojení do :agency jako :role. Tato pozvánka vyprší za :days dní.',
        'cta' => 'Přijmout pozvánku',
        'ignore' => 'Pokud jste tuto pozvánku neočekávali, můžete tento e-mail bezpečně ignorovat.',
    ],
    'roles' => [
        'agency_admin' => 'Správce',
        'agency_manager' => 'Manažer',
        'agency_staff' => 'Zaměstnanec',
    ],
];
