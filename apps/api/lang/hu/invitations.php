<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Meghívták a(z) :agency csapatához a(z) :app-ban',
        'greeting' => 'Kedves :name,',
        'body' => ':inviter meghívta Önt, hogy csatlakozzon a(z) :agency csapatához :role szerepkörben. Ez a meghívó :days nap múlva lejár.',
        'cta' => 'Meghívó elfogadása',
        'ignore' => 'Ha nem várt erre a meghívóra, biztonságosan figyelmen kívül hagyhatja ezt az e-mailt.',
    ],
    'roles' => [
        'agency_admin' => 'Adminisztrátor',
        'agency_manager' => 'Vezető',
        'agency_staff' => 'Alkalmazott',
    ],
];
