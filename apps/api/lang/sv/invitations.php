<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Du har blivit inbjuden att gå med i :agency på :app',
        'greeting' => 'Hej :name,',
        'body' => ':inviter har bjudit in dig att gå med i :agency som :role. Den här inbjudan går ut om :days dagar.',
        'cta' => 'Acceptera inbjudan',
        'ignore' => 'Om du inte väntade dig den här inbjudan kan du ignorera det här e-postmeddelandet.',
    ],
    'roles' => [
        'agency_admin' => 'Administratör',
        'agency_manager' => 'Chef',
        'agency_staff' => 'Personal',
    ],
];
