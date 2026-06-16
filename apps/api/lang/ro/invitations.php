<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Ați fost invitat să vă alăturați :agency pe :app',
        'greeting' => 'Bună ziua, :name,',
        'body' => ':inviter v-a invitat să vă alăturați :agency ca :role. Această invitație expiră în :days zile.',
        'cta' => 'Acceptați invitația',
        'ignore' => 'Dacă nu vă așteptați la această invitație, puteți ignora în siguranță acest email.',
    ],
    'roles' => [
        'agency_admin' => 'Administrator',
        'agency_manager' => 'Manager',
        'agency_staff' => 'Personal',
    ],
];
