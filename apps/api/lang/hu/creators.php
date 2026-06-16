<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Meghívták a(z) :agency csapatához a Catalystban',
            'greeting' => 'Üdvözöljük,',
            'body' => ':agency meghívta Önt, hogy csatlakozzon csapatukhoz a Catalystban. Kattintson az alábbi gombra, és állítsa be alkotói profilját.',
            'cta' => 'Kezdés',
            'expiry' => 'Ez a meghívó :date-ig érvényes.',
            'ignore' => 'Ha nem várt erre a meghívóra, biztonságosan figyelmen kívül hagyhatja ezt az e-mailt.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Catalyst-kérelme jóváhagyva',
            'greeting' => 'Kedves :name,',
            'body' => 'Nagyszerű hírek — alkotói kérelme jóváhagyva. Most már teljes hozzáféréssel rendelkezik a Catalyst irányítópulthoz.',
            'cta' => 'Ugrás az irányítópultra',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Frissítés Catalyst-kérelmével kapcsolatban',
            'greeting' => 'Kedves :name,',
            'body' => 'Köszönjük, hogy jelentkezett a Catalystba. Az ellenőrzés után jelenleg nem tudjuk jóváhagyni kérelmét.',
            'reason_label' => 'Ok',
            'resubmit_hint' => 'A kérelmet frissítheti és újra beküldheti további ellenőrzésre az irányítópultról.',
            'cta' => 'Kérelem megtekintése',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency kapcsolatba szeretne lépni Önnel a Catalystban',
            'greeting' => 'Kedves :name,',
            'body' => ':agency szeretné hozzáadni Önt csapatához a Catalystban. Nyissa meg az irányítópultot, és fogadja el vagy utasítsa el a kérést.',
            'cta' => 'Kérelem megtekintése',
            'ignore' => 'Ha nem ismeri ezt az ügynökséget, egyszerűen utasítsa el — semmi sem változik, amíg el nem fogadja.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency frissítette az együttműködési státuszát a Catalystban',
            'greeting' => 'Kedves :name,',
            'body' => ':agency frissítette az együttműködési státuszát a Catalystban. Ha kérdése van, lépjen kapcsolatba velük közvetlenül.',
            'closing' => 'Köszönjük, hogy a Catalyst részese.',
        ],
    ],
];
