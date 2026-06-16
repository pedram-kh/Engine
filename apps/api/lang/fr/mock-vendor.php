<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Vérification KYC simulée',
        'description' => 'Vous travaillez avec le fournisseur KYC simulé. Choisissez un résultat à simuler.',
        'success' => 'Terminer la vérification (réussite)',
        'fail' => 'Terminer la vérification (échec)',
        'cancel' => 'Annuler la vérification',
    ],
    'esign' => [
        'title' => 'Enveloppe de signature électronique simulée',
        'description' => 'Vous travaillez avec le fournisseur de signature électronique simulé. Choisissez un résultat à simuler.',
        'success' => "Signer l'enveloppe",
        'fail' => "Refuser l'enveloppe",
        'cancel' => 'Annuler la signature',
    ],
    'stripe' => [
        'title' => 'Intégration Stripe Connect simulée',
        'description' => 'Vous travaillez avec le fournisseur de paiement simulé. Choisissez un résultat à simuler.',
        'success' => "Terminer l'intégration",
        'fail' => "Annuler l'intégration",
    ],
    'session_unknown' => 'Session inconnue ou expirée.',
];
