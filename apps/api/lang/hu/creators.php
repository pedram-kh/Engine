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
    'admin_connected' => [
        'email' => [
            'subject' => 'Most már kapcsolatban áll a következővel: :agency a Catalyston',
            'greeting' => 'Üdvözöljük, :name!',
            'body' => 'A Catalyst adminisztrátora összekapcsolta Önt a(z) :agency ügynökséggel a platformon, a Catalyston kívül kötött megállapodás alapján. A(z) :agency mostantól láthatja a profilját, és üzenetet küldhet Önnek.',
            'unexpected' => 'Ha ez a kapcsolat váratlan, kérjük, forduljon a Catalyst ügyfélszolgálatához.',
            'cta' => 'Ugrás az irányítópultra',
        ],
    ],
    'disconnected' => [
        'email' => [
            'subject' => 'A(z) :counterparty féllel fennálló kapcsolata a Catalyston megszűnt',
            'greeting' => 'Üdvözöljük, :name!',
            'body' => 'A Catalyst adminisztrátora megszüntette a(z) :counterparty féllel fennálló munkakapcsolatát. Már nincsenek összekapcsolva a platformon.',
            'unexpected' => 'Ha ez váratlan, kérjük, forduljon a Catalyst ügyfélszolgálatához.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Fejezze be a Catalyst-fiókja beállítását',
            'greeting' => 'Szia :name,',
            'body' => 'Elkezdte létrehozni a Catalyst alkotói fiókját, de még nem erősítette meg az e-mail-címét. Erősítse meg most, hogy onnan folytathassa, ahol abbahagyta, és befejezhesse a regisztrációt.',
            'cta' => 'E-mail-cím megerősítése',
            'expiry' => 'Ez a hivatkozás :hours óra múlva lejár. Ha lejár, a bejelentkezési oldalon kérhet újat.',
            'ignore' => 'Ha nem Ön kezdeményezte ezt a regisztrációt, nyugodtan figyelmen kívül hagyhatja ezt az e-mailt.',
        ],
        'finish' => [
            'subject' => 'Fejezze be a Catalyst alkotói profilja beállítását',
            'greeting' => 'Szia :name,',
            'body' => 'Elkezdte beállítani a Catalyst alkotói profilját, de még nem fejezte be. Folytassa onnan, ahol abbahagyta, hogy befejezze a regisztrációt.',
            'cta' => 'Profil befejezése',
            'ignore' => 'Ha már befejezte a profilját, nyugodtan figyelmen kívül hagyhatja ezt az e-mailt.',
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
