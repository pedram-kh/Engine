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
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Lõpetage oma Catalysti konto seadistamine',
            'greeting' => 'Tere :name,',
            'body' => 'Alustasite oma Catalysti loojakonto loomist, kuid pole veel oma e-posti aadressi kinnitanud. Kinnitage see nüüd, et jätkata sealt, kus pooleli jäite, ja lõpetada registreerimine.',
            'cta' => 'Kinnita e-post',
            'expiry' => 'See link aegub :hours tunni pärast. Kui see aegub, saate sisselogimislehelt uue taotleda.',
            'ignore' => 'Kui teie ei alustanud seda registreerimist, võite selle e-kirja rahulikult eirata.',
        ],
        'finish' => [
            'subject' => 'Lõpetage oma Catalysti loojaprofiili seadistamine',
            'greeting' => 'Tere :name,',
            'body' => 'Alustasite oma Catalysti loojaprofiili seadistamist, kuid pole seda veel lõpetanud. Jätkake sealt, kus pooleli jäite, et registreerimine lõpetada.',
            'cta' => 'Lõpeta profiil',
            'ignore' => 'Kui olete oma profiili juba lõpetanud, võite selle e-kirja rahulikult eirata.',
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
