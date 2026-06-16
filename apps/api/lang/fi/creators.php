<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Sinut on kutsuttu liittymään :agency-ryhmään Catalystissa',
            'greeting' => 'Hei,',
            'body' => ':agency kutsui sinut liittymään tiimiinsä Catalystissa. Napsauta alla olevaa painiketta ja määritä luojaprofiilisi.',
            'cta' => 'Aloita',
            'expiry' => 'Tämä kutsu vanhenee :date.',
            'ignore' => 'Jos et odottanut tätä kutsua, voit turvallisesti jättää tämän sähköpostin huomiotta.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Catalyst-hakemuksesi on hyväksytty',
            'greeting' => 'Hei, :name,',
            'body' => 'Loistavia uutisia — luojahakemuksesi on hyväksytty. Sinulla on nyt täysi pääsy Catalyst-hallintapaneeliin.',
            'cta' => 'Siirry hallintapaneeliin',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Päivitys Catalyst-hakemuksestasi',
            'greeting' => 'Hei, :name,',
            'body' => 'Kiitos Catalyst-hakemuksestasi. Tarkistuksen jälkeen emme tällä hetkellä voi hyväksyä hakemustasi.',
            'reason_label' => 'Syy',
            'resubmit_hint' => 'Voit päivittää ja lähettää hakemuksen uudelleen jatkotarkistusta varten hallintapaneelista.',
            'cta' => 'Tarkista hakemus',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency haluaa muodostaa yhteyden kanssasi Catalystissa',
            'greeting' => 'Hei, :name,',
            'body' => ':agency haluaa lisätä sinut tiimiinsä Catalystissa. Avaa hallintapaneeli ja hyväksy tai hylkää pyyntö.',
            'cta' => 'Katso pyyntö',
            'ignore' => 'Jos et tunne tätä toimistoa, hylkää se — mikään ei muutu ennen kuin hyväksyt.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency on päivittänyt yhteistyöstatuksesi Catalystissa',
            'greeting' => 'Hei, :name,',
            'body' => ':agency on päivittänyt yhteistyöstatuksesi Catalystissa. Jos sinulla on kysyttävää, ota yhteyttä heihin suoraan.',
            'closing' => 'Kiitos, että olet osa Catalystia.',
        ],
    ],
];
