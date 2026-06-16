<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Testi-KYC-tarkistus',
        'description' => 'Käytät testi-KYC-palveluntarjoajaa. Valitse simuloitava tulos.',
        'success' => 'Suorita tarkistus (onnistuu)',
        'fail' => 'Suorita tarkistus (epäonnistuu)',
        'cancel' => 'Peruuta tarkistus',
    ],
    'esign' => [
        'title' => 'Testi-sähköisen allekirjoituksen kirjekuori',
        'description' => 'Käytät testi-sähköisen allekirjoituksen palveluntarjoajaa. Valitse simuloitava tulos.',
        'success' => 'Allekirjoita kirjekuori',
        'fail' => 'Hylkää kirjekuori',
        'cancel' => 'Peruuta allekirjoittaminen',
    ],
    'stripe' => [
        'title' => 'Testi-Stripe Connect -liittyminen',
        'description' => 'Käytät testi-maksupalveluntarjoajaa. Valitse simuloitava tulos.',
        'success' => 'Suorita liittyminen',
        'fail' => 'Peruuta liittyminen',
    ],
    'session_unknown' => 'Tuntematon tai vanhentunut istunto.',
];
