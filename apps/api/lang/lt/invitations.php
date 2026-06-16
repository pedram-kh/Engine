<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Esate pakviesti prisijungti prie :agency platformoje :app',
        'greeting' => 'Sveiki, :name,',
        'body' => ':inviter pakvietė jus prisijungti prie :agency kaip :role. Šis kvietimas baigia galioti po :days dienų.',
        'cta' => 'Priimti kvietimą',
        'ignore' => 'Jei nelaukėte šio kvietimo, galite saugiai ignoruoti šį el. laišką.',
    ],
    'roles' => [
        'agency_admin' => 'Administratorius',
        'agency_manager' => 'Vadybininkas',
        'agency_staff' => 'Darbuotojas',
    ],
];
