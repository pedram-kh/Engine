<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Verifica KYC simulata',
        'description' => 'Sei collegato al provider KYC simulato. Scegli un esito da simulare.',
        'success' => 'Completa verifica (successo)',
        'fail' => 'Completa verifica (fallita)',
        'cancel' => 'Annulla verifica',
    ],
    'esign' => [
        'title' => 'Busta di firma simulata',
        'description' => 'Sei collegato al provider di firma simulato. Scegli un esito da simulare.',
        'success' => 'Firma busta',
        'fail' => 'Rifiuta busta',
        'cancel' => 'Annulla firma',
    ],
    'stripe' => [
        'title' => 'Onboarding Stripe Connect simulato',
        'description' => 'Sei collegato al provider di pagamento simulato. Scegli un esito da simulare.',
        'success' => 'Completa onboarding',
        'fail' => 'Annulla onboarding',
    ],
    'session_unknown' => 'Sessione sconosciuta o scaduta.',
];
