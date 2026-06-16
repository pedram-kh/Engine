<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Verificare KYC de test',
        'description' => 'Lucrați cu un furnizor KYC de test. Selectați un rezultat pentru simulare.',
        'success' => 'Finalizați verificarea (succes)',
        'fail' => 'Finalizați verificarea (eșec)',
        'cancel' => 'Anulați verificarea',
    ],
    'esign' => [
        'title' => 'Plic de semnătură electronică de test',
        'description' => 'Lucrați cu un furnizor de semnătură electronică de test. Selectați un rezultat pentru simulare.',
        'success' => 'Semnați plicul',
        'fail' => 'Refuzați plicul',
        'cancel' => 'Anulați semnarea',
    ],
    'stripe' => [
        'title' => 'Integrare Stripe Connect de test',
        'description' => 'Lucrați cu un furnizor de plăți de test. Selectați un rezultat pentru simulare.',
        'success' => 'Finalizați integrarea',
        'fail' => 'Anulați integrarea',
    ],
    'session_unknown' => 'Sesiune necunoscută sau expirată.',
];
