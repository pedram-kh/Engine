<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Gesimuleerde KYC-verificatie',
        'description' => 'Je werkt met de gesimuleerde KYC-provider. Kies een resultaat om te simuleren.',
        'success' => 'Verificatie voltooien (geslaagd)',
        'fail' => 'Verificatie voltooien (mislukt)',
        'cancel' => 'Verificatie annuleren',
    ],
    'esign' => [
        'title' => 'Gesimuleerde e-handtekeningenvelop',
        'description' => 'Je werkt met de gesimuleerde e-handtekening-provider. Kies een resultaat om te simuleren.',
        'success' => 'Envelop ondertekenen',
        'fail' => 'Envelop weigeren',
        'cancel' => 'Ondertekening annuleren',
    ],
    'stripe' => [
        'title' => 'Gesimuleerde Stripe Connect-onboarding',
        'description' => 'Je werkt met de gesimuleerde betalingsprovider. Kies een resultaat om te simuleren.',
        'success' => 'Onboarding voltooien',
        'fail' => 'Onboarding annuleren',
    ],
    'session_unknown' => 'Onbekende of verlopen sessie.',
];
