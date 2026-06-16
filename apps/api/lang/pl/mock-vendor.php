<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Testowa weryfikacja KYC',
        'description' => 'Działasz z testowym dostawcą KYC. Wybierz wynik do symulacji.',
        'success' => 'Zakończ weryfikację (sukces)',
        'fail' => 'Zakończ weryfikację (niepowodzenie)',
        'cancel' => 'Anuluj weryfikację',
    ],
    'esign' => [
        'title' => 'Testowa koperta e-podpisu',
        'description' => 'Działasz z testowym dostawcą e-podpisu. Wybierz wynik do symulacji.',
        'success' => 'Podpisz kopertę',
        'fail' => 'Odrzuć kopertę',
        'cancel' => 'Anuluj podpisywanie',
    ],
    'stripe' => [
        'title' => 'Testowy onboarding Stripe Connect',
        'description' => 'Działasz z testowym dostawcą płatności. Wybierz wynik do symulacji.',
        'success' => 'Zakończ onboarding',
        'fail' => 'Anuluj onboarding',
    ],
    'session_unknown' => 'Nieznana lub wygasła sesja.',
];
