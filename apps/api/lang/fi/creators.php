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
    'admin_connected' => [
        'email' => [
            'subject' => 'Sinut on nyt yhdistetty agentuuriin :agency Catalystissa',
            'greeting' => 'Hei :name,',
            'body' => 'Catalystin ylläpitäjä on yhdistänyt sinut agentuuriin :agency alustalla Catalystin ulkopuolella tehdyn sopimuksen perusteella. :agency näkee nyt profiilisi ja voi lähettää sinulle viestejä.',
            'unexpected' => 'Jos tämä yhteys on odottamaton, ota yhteyttä Catalystin tukeen.',
            'cta' => 'Siirry hallintapaneeliin',
        ],
    ],
    'disconnected' => [
        'email' => [
            'subject' => 'Yhteytesi osapuoleen :counterparty Catalystissa on päättynyt',
            'greeting' => 'Hei :name,',
            'body' => 'Catalystin ylläpitäjä on päättänyt työsuhteesi osapuoleen :counterparty. Ette ole enää yhteydessä alustalla.',
            'unexpected' => 'Jos tämä on odottamatonta, ota yhteyttä Catalystin tukeen.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Viimeistele Catalyst-tilisi käyttöönotto',
            'greeting' => 'Hei :name,',
            'body' => 'Aloitit Catalyst-tekijätilisi luomisen, mutta et ole vielä vahvistanut sähköpostiosoitettasi. Vahvista se nyt jatkaaksesi siitä, mihin jäit, ja viimeistelläksesi rekisteröitymisen.',
            'cta' => 'Vahvista sähköpostini',
            'expiry' => 'Tämä linkki vanhenee :hours tunnin kuluttua. Jos se vanhenee, voit pyytää uuden kirjautumissivulta.',
            'ignore' => 'Jos et aloittanut tätä rekisteröitymistä, voit jättää tämän viestin huomiotta.',
        ],
        'finish' => [
            'subject' => 'Viimeistele Catalyst-tekijäprofiilisi',
            'greeting' => 'Hei :name,',
            'body' => 'Aloitit Catalyst-tekijäprofiilisi määrittämisen, mutta et ole vielä saanut sitä valmiiksi. Jatka siitä, mihin jäit, viimeistelläksesi rekisteröitymisen.',
            'cta' => 'Viimeistele profiilini',
            'ignore' => 'Jos olet jo viimeistellyt profiilisi, voit jättää tämän viestin huomiotta.',
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
