<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Simuleret KYC-verifikation',
        'description' => 'Du arbejder med den simulerede KYC-udbyder. Vælg et resultat at simulere.',
        'success' => 'Afslut verifikation (succes)',
        'fail' => 'Afslut verifikation (fejl)',
        'cancel' => 'Annuller verifikation',
    ],
    'esign' => [
        'title' => 'Simuleret e-signaturkuvert',
        'description' => 'Du arbejder med den simulerede e-signatur-udbyder. Vælg et resultat at simulere.',
        'success' => 'Underskriv kuvert',
        'fail' => 'Afvis kuvert',
        'cancel' => 'Annuller underskrivning',
    ],
    'stripe' => [
        'title' => 'Simuleret Stripe Connect-onboarding',
        'description' => 'Du arbejder med den simulerede betalingsudbyder. Vælg et resultat at simulere.',
        'success' => 'Afslut onboarding',
        'fail' => 'Annuller onboarding',
    ],
    'session_unknown' => 'Ukendt eller udløbet session.',
];
