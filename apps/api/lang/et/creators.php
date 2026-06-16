<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Teid on kutsutud liituma :agency-ga Catalystis',
            'greeting' => 'Tere,',
            'body' => ':agency kutsus teid liituma nende meeskonnaga Catalystis. Klõpsake alloleval nupul ja seadistage oma looja profiil.',
            'cta' => 'Alusta',
            'expiry' => 'See kutse aegub :date.',
            'ignore' => 'Kui te seda kutset ei oodanud, võite selle e-kirja turvaliselt ignoreerida.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Teie Catalysti taotlus on kinnitatud',
            'greeting' => 'Tere, :name,',
            'body' => 'Suurepärane uudis — teie looja taotlus on kinnitatud. Teil on nüüd täielik juurdepääs Catalysti juhtpaneelile.',
            'cta' => 'Mine juhtpaneelile',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Uuendus teie Catalysti taotluse kohta',
            'greeting' => 'Tere, :name,',
            'body' => 'Täname, et taotlesite Catalysti. Pärast ülevaatamist ei saa me praegu teie taotlust kinnitada.',
            'reason_label' => 'Põhjus',
            'resubmit_hint' => 'Saate oma taotlust värskendada ja täiendavaks ülevaatamiseks juhtpaneelilt uuesti esitada.',
            'cta' => 'Vaata taotlust',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency soovib teiega Catalystis ühendust võtta',
            'greeting' => 'Tere, :name,',
            'body' => ':agency soovib teid oma meeskonda Catalystis lisada. Avage juhtpaneel ja nõustuge päringuga või lükake see tagasi.',
            'cta' => 'Vaata päringut',
            'ignore' => 'Kui te seda agentuuri ei tunne, lükkake see lihtsalt tagasi — midagi ei muutu, kuni te ei nõustu.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency uuendas teie koostöö staatust Catalystis',
            'greeting' => 'Tere, :name,',
            'body' => ':agency uuendas teie koostöö staatust Catalystis. Kui teil on küsimusi, võtke nendega otse ühendust.',
            'closing' => 'Täname, et olete Catalysti osa.',
        ],
    ],
];
