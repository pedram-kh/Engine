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
    'admin_connected' => [
        'email' => [
            'subject' => 'Sada ste povezani s :agency na Catalystu',
            'greeting' => 'Pozdrav :name,',
            'body' => 'Administrator Catalysta povezao vas je s :agency na platformi na temelju dogovora postignutog izvan Catalysta. :agency sada može vidjeti vaš profil i slati vam poruke.',
            'unexpected' => 'Ako je ova veza neočekivana, obratite se podršci Catalysta.',
            'cta' => 'Idite na svoju nadzornu ploču',
        ],
    ],
    'disconnected' => [
        'email' => [
            'subject' => 'Vaša veza s :counterparty na Catalystu je završena',
            'greeting' => 'Pozdrav :name,',
            'body' => 'Administrator Catalysta prekinuo je vašu radnu vezu s :counterparty. Više niste povezani na platformi.',
            'unexpected' => 'Ako je ovo neočekivano, obratite se podršci Catalysta.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Dovršite postavljanje svojeg Catalyst računa',
            'greeting' => 'Bok :name,',
            'body' => 'Započeli ste izradu svojeg računa autora na Catalystu, ali još niste potvrdili svoju adresu e-pošte. Potvrdite je sada kako biste nastavili gdje ste stali i dovršili registraciju.',
            'cta' => 'Potvrdi e-poštu',
            'expiry' => 'Ova poveznica istječe za :hours sati. Ako istekne, možete zatražiti novu na stranici za prijavu.',
            'ignore' => 'Ako niste vi započeli ovu registraciju, možete slobodno zanemariti ovu poruku.',
        ],
        'finish' => [
            'subject' => 'Dovršite postavljanje svojeg Catalyst profila autora',
            'greeting' => 'Bok :name,',
            'body' => 'Započeli ste postavljanje svojeg profila autora na Catalystu, ali još ga niste dovršili. Nastavite gdje ste stali kako biste dovršili registraciju.',
            'cta' => 'Dovrši profil',
            'ignore' => 'Ako ste već dovršili svoj profil, možete slobodno zanemariti ovu poruku.',
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
