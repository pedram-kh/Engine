<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Pozvani ste u :agency na Catalyst',
            'greeting' => 'Pozdrav,',
            'body' => ':agency vas je pozvala da se pridružite njihovoj postavi na Catalyst. Kliknite gumb u nastavku i postavite svoj kreatorski profil.',
            'cta' => 'Počni',
            'expiry' => 'Ovaj poziv ističe :date.',
            'ignore' => 'Ako niste očekivali ovaj poziv, možete sigurno zanemariti ovu e-poštu.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Vaša prijava za Catalyst je odobrena',
            'greeting' => 'Pozdrav, :name,',
            'body' => 'Odlične vijesti — vaša kreatorska prijava je odobrena. Sada imate pun pristup Catalyst upravljačkoj ploči.',
            'cta' => 'Idi na upravljačku ploču',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Ažuriranje vaše prijave za Catalyst',
            'greeting' => 'Pozdrav, :name,',
            'body' => 'Hvala na prijavi za Catalyst. Nakon pregleda, trenutno ne možemo odobriti vašu prijavu.',
            'reason_label' => 'Razlog',
            'resubmit_hint' => 'Možete ažurirati i ponovo poslati prijavu na daljnji pregled s upravljačke ploče.',
            'cta' => 'Pregledaj prijavu',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency se želi povezati s vama na Catalyst',
            'greeting' => 'Pozdrav, :name,',
            'body' => ':agency bi vas voljela dodati u svoju postavu na Catalyst. Otvorite upravljačku ploču i prihvatite ili odbijte zahtjev.',
            'cta' => 'Pogledaj zahtjev',
            'ignore' => 'Ako ne poznajete ovu agenciju, jednostavno je odbijte — ništa se neće promijeniti dok ne prihvatite.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency je ažurirala vaš status suradnje na Catalyst',
            'greeting' => 'Pozdrav, :name,',
            'body' => ':agency je ažurirala vaš status suradnje na Catalyst. Ako imate bilo kakvih pitanja, kontaktirajte ih izravno.',
            'closing' => 'Hvala što ste dio Catalysta.',
        ],
    ],
];
