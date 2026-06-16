<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Testovacie overenie KYC',
        'description' => 'Pracujete s testovacím poskytovateľom KYC. Zvoľte výsledok na simuláciu.',
        'success' => 'Dokončiť overenie (úspech)',
        'fail' => 'Dokončiť overenie (neúspech)',
        'cancel' => 'Zrušiť overenie',
    ],
    'esign' => [
        'title' => 'Testovacia obálka e-podpisu',
        'description' => 'Pracujete s testovacím poskytovateľom e-podpisu. Zvoľte výsledok na simuláciu.',
        'success' => 'Podpísať obálku',
        'fail' => 'Odmietnuť obálku',
        'cancel' => 'Zrušiť podpisovanie',
    ],
    'stripe' => [
        'title' => 'Testovací onboarding Stripe Connect',
        'description' => 'Pracujete s testovacím poskytovateľom platieb. Zvoľte výsledok na simuláciu.',
        'success' => 'Dokončiť onboarding',
        'fail' => 'Zrušiť onboarding',
    ],
    'session_unknown' => 'Neznáma alebo vypršaná relácia.',
];
