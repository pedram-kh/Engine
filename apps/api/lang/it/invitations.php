<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Sei stato invitato a unirti a :agency su :app',
        'greeting' => 'Ciao :name,',
        'body' => ':inviter ti ha invitato a unirti a :agency come :role. Questo invito scade tra :days giorni.',
        'cta' => 'Accetta Invito',
        'ignore' => 'Se non ti aspettavi questo invito, puoi ignorare questa email in sicurezza.',
    ],
    'roles' => [
        'agency_admin' => 'Amministratore',
        'agency_manager' => 'Manager',
        'agency_staff' => 'Staff',
    ],
];
