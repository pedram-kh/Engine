<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Testna KYC provjera',
        'description' => 'Radite s testnim KYC pružateljem. Odaberite rezultat za simulaciju.',
        'success' => 'Dovrši provjeru (uspjeh)',
        'fail' => 'Dovrši provjeru (neuspjeh)',
        'cancel' => 'Otkaži provjeru',
    ],
    'esign' => [
        'title' => 'Testna omotnica e-potpisa',
        'description' => 'Radite s testnim pružateljem e-potpisa. Odaberite rezultat za simulaciju.',
        'success' => 'Potpiši omotnicu',
        'fail' => 'Odbij omotnicu',
        'cancel' => 'Otkaži potpisivanje',
    ],
    'stripe' => [
        'title' => 'Testno uključivanje Stripe Connect',
        'description' => 'Radite s testnim pružateljem plaćanja. Odaberite rezultat za simulaciju.',
        'success' => 'Dovrši uključivanje',
        'fail' => 'Otkaži uključivanje',
    ],
    'session_unknown' => 'Nepoznata ili istekla sesija.',
];
