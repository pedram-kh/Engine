<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Vous avez été invité à rejoindre :agency sur :app',
        'greeting' => 'Bonjour :name,',
        'body' => ':inviter vous a invité à rejoindre :agency en tant que :role. Cette invitation expire dans :days jours.',
        'cta' => "Accepter l'invitation",
        'ignore' => 'Si vous ne vous attendiez pas à cette invitation, vous pouvez ignorer cet e-mail en toute sécurité.',
    ],
    'roles' => [
        'agency_admin' => 'Administrateur',
        'agency_manager' => 'Responsable',
        'agency_staff' => 'Personnel',
    ],
];
