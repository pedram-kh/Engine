<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Povabljeni ste bili v :agency na Catalyst',
            'greeting' => 'Pozdravljeni,',
            'body' => ':agency vas je povabila, da se pridružite njihovi zasedbi na Catalyst. Kliknite spodnji gumb in nastavite svoj ustvarjalski profil.',
            'cta' => 'Začni',
            'expiry' => 'To povabilo poteče :date.',
            'ignore' => 'Če tega povabila niste pričakovali, lahko to e-sporočilo varno prezrete.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Vaša prijava za Catalyst je bila odobrena',
            'greeting' => 'Pozdravljeni, :name,',
            'body' => 'Odlične novice — vaša prijava ustvarjalca je bila odobrena. Zdaj imate poln dostop do nadzorne plošče Catalyst.',
            'cta' => 'Pojdi na nadzorno ploščo',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Posodobitev vaše prijave za Catalyst',
            'greeting' => 'Pozdravljeni, :name,',
            'body' => 'Hvala za prijavo na Catalyst. Po pregledu vaše prijave trenutno ne moremo odobriti.',
            'reason_label' => 'Razlog',
            'resubmit_hint' => 'Prijavo lahko posodobite in znova oddate za nadaljnji pregled z nadzorne plošče.',
            'cta' => 'Preglej prijavo',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency se želi povezati z vami na Catalyst',
            'greeting' => 'Pozdravljeni, :name,',
            'body' => ':agency bi vas rada dodala v svojo zasedbo na Catalyst. Odprite nadzorno ploščo in sprejmite ali zavrnite zahtevo.',
            'cta' => 'Poglej zahtevo',
            'ignore' => 'Če te agencije ne poznate, jo preprosto zavrnite — nič se ne bo spremenilo, dokler ne sprejmete.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Dokončajte nastavitev svojega računa Catalyst',
            'greeting' => 'Pozdravljeni, :name,',
            'body' => 'Začeli ste ustvarjati svoj račun ustvarjalca na Catalystu, vendar še niste potrdili svojega e-poštnega naslova. Potrdite ga zdaj, da nadaljujete, kjer ste ostali, in dokončate registracijo.',
            'cta' => 'Potrdi e-pošto',
            'expiry' => 'Ta povezava poteče čez :hours ur. Če poteče, lahko na prijavni strani zahtevate novo.',
            'ignore' => 'Če te registracije niste začeli vi, lahko to sporočilo mirno prezrete.',
        ],
        'finish' => [
            'subject' => 'Dokončajte nastavitev svojega profila ustvarjalca na Catalystu',
            'greeting' => 'Pozdravljeni, :name,',
            'body' => 'Začeli ste nastavljati svoj profil ustvarjalca na Catalystu, vendar ga še niste dokončali. Nadaljujte, kjer ste ostali, da dokončate registracijo.',
            'cta' => 'Dokončaj profil',
            'ignore' => 'Če ste svoj profil že dokončali, lahko to sporočilo mirno prezrete.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency je posodobila vaš status sodelovanja na Catalyst',
            'greeting' => 'Pozdravljeni, :name,',
            'body' => ':agency je posodobila vaš status sodelovanja na Catalyst. Če imate kakršna koli vprašanja, jih kontaktirajte neposredno.',
            'closing' => 'Hvala, ker ste del Catalysta.',
        ],
    ],
];
