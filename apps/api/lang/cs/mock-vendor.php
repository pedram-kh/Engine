<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Testovací ověření KYC',
        'description' => 'Pracujete s testovacím poskytovatelem KYC. Zvolte výsledek k simulaci.',
        'success' => 'Dokončit ověření (úspěch)',
        'fail' => 'Dokončit ověření (neúspěch)',
        'cancel' => 'Zrušit ověření',
    ],
    'esign' => [
        'title' => 'Testovací obálka e-podpisu',
        'description' => 'Pracujete s testovacím poskytovatelem e-podpisu. Zvolte výsledek k simulaci.',
        'success' => 'Podepsat obálku',
        'fail' => 'Odmítnout obálku',
        'cancel' => 'Zrušit podepisování',
    ],
    'stripe' => [
        'title' => 'Testovací onboarding Stripe Connect',
        'description' => 'Pracujete s testovacím poskytovatelem plateb. Zvolte výsledek k simulaci.',
        'success' => 'Dokončit onboarding',
        'fail' => 'Zrušit onboarding',
    ],
    'session_unknown' => 'Neznámá nebo vypršelá relace.',
];
