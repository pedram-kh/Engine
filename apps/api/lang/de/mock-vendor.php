<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Simulierte KYC-Verifizierung',
        'description' => 'Du arbeitest mit dem simulierten KYC-Anbieter. Wähle ein Ergebnis zur Simulation.',
        'success' => 'Verifizierung abschließen (Erfolg)',
        'fail' => 'Verifizierung abschließen (Fehler)',
        'cancel' => 'Verifizierung abbrechen',
    ],
    'esign' => [
        'title' => 'Simulierter E-Signatur-Umschlag',
        'description' => 'Du arbeitest mit dem simulierten E-Signatur-Anbieter. Wähle ein Ergebnis zur Simulation.',
        'success' => 'Umschlag unterzeichnen',
        'fail' => 'Umschlag ablehnen',
        'cancel' => 'Signierung abbrechen',
    ],
    'stripe' => [
        'title' => 'Simuliertes Stripe Connect-Onboarding',
        'description' => 'Du arbeitest mit dem simulierten Zahlungsanbieter. Wähle ein Ergebnis zur Simulation.',
        'success' => 'Onboarding abschließen',
        'fail' => 'Onboarding abbrechen',
    ],
    'session_unknown' => 'Unbekannte oder abgelaufene Sitzung.',
];
