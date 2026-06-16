<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Je bent uitgenodigd om :agency op :app te joinen',
        'greeting' => 'Hallo :name,',
        'body' => ':inviter heeft je uitgenodigd om lid te worden van :agency als :role. Deze uitnodiging verloopt over :days dagen.',
        'cta' => 'Uitnodiging accepteren',
        'ignore' => 'Als je deze uitnodiging niet verwachtte, kun je deze e-mail negeren.',
    ],
    'roles' => [
        'agency_admin' => 'Beheerder',
        'agency_manager' => 'Manager',
        'agency_staff' => 'Medewerker',
    ],
];
