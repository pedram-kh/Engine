<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Du wurdest eingeladen, :agency auf :app beizutreten',
        'greeting' => 'Hallo :name,',
        'body' => ':inviter hat dich eingeladen, :agency als :role beizutreten. Diese Einladung läuft in :days Tagen ab.',
        'cta' => 'Einladung annehmen',
        'ignore' => 'Wenn du diese Einladung nicht erwartet hast, kannst du diese E-Mail ignorieren.',
    ],
    'roles' => [
        'agency_admin' => 'Administrator',
        'agency_manager' => 'Manager',
        'agency_staff' => 'Mitarbeiter',
    ],
];
