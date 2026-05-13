<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Você foi convidado para se juntar à :agency em :app',
        'greeting' => 'Olá :name,',
        'body' => ':inviter convidou você para se juntar à :agency como :role. Este convite expira em :days dias.',
        'cta' => 'Aceitar Convite',
        'ignore' => 'Se você não esperava este convite, pode ignorar este e-mail com segurança.',
    ],
    'roles' => [
        'agency_admin' => 'Administrador',
        'agency_manager' => 'Gerente',
        'agency_staff' => 'Colaborador',
    ],
];
