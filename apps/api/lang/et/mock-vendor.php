<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Testi KYC kontroll',
        'description' => 'Töötate testi KYC teenusepakkujaga. Valige simuleerimiseks tulemus.',
        'success' => 'Lõpeta kontroll (edukas)',
        'fail' => 'Lõpeta kontroll (ebaõnnestunud)',
        'cancel' => 'Tühista kontroll',
    ],
    'esign' => [
        'title' => 'Testi e-allkirja ümbrik',
        'description' => 'Töötate testi e-allkirja teenusepakkujaga. Valige simuleerimiseks tulemus.',
        'success' => 'Allkirjasta ümbrik',
        'fail' => 'Keeldu ümbrikust',
        'cancel' => 'Tühista allkirjastamine',
    ],
    'stripe' => [
        'title' => 'Testi Stripe Connecti liitumine',
        'description' => 'Töötate testi makseteenuse pakkujaga. Valige simuleerimiseks tulemus.',
        'success' => 'Lõpeta liitumine',
        'fail' => 'Tühista liitumine',
    ],
    'session_unknown' => 'Tundmatu või aegunud seanss.',
];
