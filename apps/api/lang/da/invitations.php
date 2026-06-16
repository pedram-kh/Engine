<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Du er blevet inviteret til at tilslutte dig :agency på :app',
        'greeting' => 'Hej :name,',
        'body' => ':inviter har inviteret dig til at tilslutte dig :agency som :role. Denne invitation udløber om :days dage.',
        'cta' => 'Accepter invitation',
        'ignore' => 'Hvis du ikke forventede denne invitation, kan du ignorere denne e-mail.',
    ],
    'roles' => [
        'agency_admin' => 'Administrator',
        'agency_manager' => 'Manager',
        'agency_staff' => 'Medarbejder',
    ],
];
