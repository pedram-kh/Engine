<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Te han invitado a unirte a :agency en :app',
        'greeting' => 'Hola :name:',
        'body' => ':inviter te ha invitado a unirte a :agency como :role. Esta invitación caduca en :days días.',
        'cta' => 'Aceptar invitación',
        'ignore' => 'Si no esperabas esta invitación, puedes ignorar este correo con total tranquilidad.',
    ],
    'roles' => [
        'agency_admin' => 'Administrador',
        'agency_manager' => 'Gestor',
        'agency_staff' => 'Personal',
    ],
];
