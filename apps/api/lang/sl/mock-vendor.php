<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Testna KYC preveritev',
        'description' => 'Delate s testnim ponudnikom KYC. Izberite rezultat za simulacijo.',
        'success' => 'Dokončaj preveritev (uspeh)',
        'fail' => 'Dokončaj preveritev (neuspeh)',
        'cancel' => 'Prekliči preveritev',
    ],
    'esign' => [
        'title' => 'Testna ovojnica e-podpisa',
        'description' => 'Delate s testnim ponudnikom e-podpisa. Izberite rezultat za simulacijo.',
        'success' => 'Podpiši ovojnico',
        'fail' => 'Zavrni ovojnico',
        'cancel' => 'Prekliči podpisovanje',
    ],
    'stripe' => [
        'title' => 'Testno vgrajevanje Stripe Connect',
        'description' => 'Delate s testnim ponudnikom plačil. Izberite rezultat za simulacijo.',
        'success' => 'Dokončaj vgrajevanje',
        'fail' => 'Prekliči vgrajevanje',
    ],
    'session_unknown' => 'Neznana ali potekla seja.',
];
