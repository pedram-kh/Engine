<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Поканени сте да се присъедините към :agency в :app',
        'greeting' => 'Здравей, :name,',
        'body' => ':inviter ви покани да се присъедините към :agency като :role. Тази покана изтича след :days дни.',
        'cta' => 'Приеми поканата',
        'ignore' => 'Ако не сте очаквали тази покана, можете спокойно да игнорирате този имейл.',
    ],
    'roles' => [
        'agency_admin' => 'Администратор',
        'agency_manager' => 'Мениджър',
        'agency_staff' => 'Служител',
    ],
];
