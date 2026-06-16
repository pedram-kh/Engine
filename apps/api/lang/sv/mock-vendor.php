<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Mock KYC-verifiering',
        'description' => 'Du kör mot mock KYC-leverantören. Välj ett resultat att simulera.',
        'success' => 'Slutför verifiering (lyckad)',
        'fail' => 'Slutför verifiering (misslyckad)',
        'cancel' => 'Avbryt verifiering',
    ],
    'esign' => [
        'title' => 'Mock e-signaturskonvolut',
        'description' => 'Du kör mot mock e-signaturleverantören. Välj ett resultat att simulera.',
        'success' => 'Signera konvolut',
        'fail' => 'Avvisa konvolut',
        'cancel' => 'Avbryt signering',
    ],
    'stripe' => [
        'title' => 'Mock Stripe Connect-registrering',
        'description' => 'Du kör mot mock betalningsleverantören. Välj ett resultat att simulera.',
        'success' => 'Slutför registrering',
        'fail' => 'Avbryt registrering',
    ],
    'session_unknown' => 'Okänd eller utgången session.',
];
