<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Boli ste pozvaní do :agency na Catalyst',
            'greeting' => 'Dobrý deň,',
            'body' => ':agency vás pozvala k pripojeniu do svojho rosteru na Catalyst. Kliknite na tlačidlo nižšie a nastavte svoj tvorivý profil.',
            'cta' => 'Začať',
            'expiry' => 'Táto pozvánka vyprší :date.',
            'ignore' => 'Ak ste túto pozvánku neočakávali, môžete tento e-mail bezpečne ignorovať.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Vaša žiadosť do Catalyst bola schválená',
            'greeting' => 'Dobrý deň, :name,',
            'body' => 'Skvelé správy — vaša žiadosť tvorcu bola schválená. Teraz máte plný prístup k ovládaciemu panelu Catalyst.',
            'cta' => 'Prejsť na ovládací panel',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Aktualizácia vašej žiadosti do Catalyst',
            'greeting' => 'Dobrý deň, :name,',
            'body' => 'Ďakujeme za žiadosť do Catalyst. Po posúdení nemôžeme vašu žiadosť momentálne schváliť.',
            'reason_label' => 'Dôvod',
            'resubmit_hint' => 'Žiadosť môžete aktualizovať a znovu odoslať na ďalšie posúdenie z ovládacieho panelu.',
            'cta' => 'Skontrolovať žiadosť',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency sa s vami chce prepojiť na Catalyst',
            'greeting' => 'Dobrý deň, :name,',
            'body' => ':agency by vás rada pridala do svojho rosteru na Catalyst. Otvorte ovládací panel a prijmite alebo odmietnite žiadosť.',
            'cta' => 'Zobraziť žiadosť',
            'ignore' => 'Ak túto agentúru nepoznáte, môžete ju jednoducho odmietnuť — nič sa nezmení, kým neprijmete.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Dokončite nastavenie svojho účtu Catalyst',
            'greeting' => 'Dobrý deň, :name,',
            'body' => 'Začali ste si vytvárať účet tvorcu na Catalyst, ale zatiaľ ste nepotvrdili svoju e-mailovú adresu. Potvrďte ju teraz, aby ste mohli pokračovať tam, kde ste prestali, a dokončiť registráciu.',
            'cta' => 'Potvrdiť e-mail',
            'expiry' => 'Tento odkaz vyprší o :hours hodín. Ak vyprší, môžete si vyžiadať nový na prihlasovacej stránke.',
            'ignore' => 'Ak ste túto registráciu nezačali, môžete tento e-mail ignorovať.',
        ],
        'finish' => [
            'subject' => 'Dokončite nastavenie svojho profilu tvorcu na Catalyst',
            'greeting' => 'Dobrý deň, :name,',
            'body' => 'Začali ste si nastavovať profil tvorcu na Catalyst, ale zatiaľ ste ho nedokončili. Pokračujte tam, kde ste prestali, a dokončite registráciu.',
            'cta' => 'Dokončiť profil',
            'ignore' => 'Ak už máte profil dokončený, môžete tento e-mail ignorovať.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency aktualizovala váš stav spolupráce na Catalyst',
            'greeting' => 'Dobrý deň, :name,',
            'body' => ':agency aktualizovala váš stav spolupráce na Catalyst. Ak máte akékoľvek otázky, kontaktujte ich priamo.',
            'closing' => 'Ďakujeme, že ste súčasťou Catalyst.',
        ],
    ],
];
