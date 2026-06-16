<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Teszt KYC-ellenőrzés',
        'description' => 'Teszt KYC-szolgáltatóval dolgozik. Válasszon eredményt szimuláláshoz.',
        'success' => 'Ellenőrzés befejezése (sikeres)',
        'fail' => 'Ellenőrzés befejezése (sikertelen)',
        'cancel' => 'Ellenőrzés megszakítása',
    ],
    'esign' => [
        'title' => 'Teszt e-aláírási boríték',
        'description' => 'Teszt e-aláírási szolgáltatóval dolgozik. Válasszon eredményt szimuláláshoz.',
        'success' => 'Boríték aláírása',
        'fail' => 'Boríték elutasítása',
        'cancel' => 'Aláírás megszakítása',
    ],
    'stripe' => [
        'title' => 'Teszt Stripe Connect csatlakozás',
        'description' => 'Teszt fizetési szolgáltatóval dolgozik. Válasszon eredményt szimuláláshoz.',
        'success' => 'Csatlakozás befejezése',
        'fail' => 'Csatlakozás megszakítása',
    ],
    'session_unknown' => 'Ismeretlen vagy lejárt munkamenet.',
];
