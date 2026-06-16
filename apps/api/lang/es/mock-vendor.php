<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Verificación KYC simulada',
        'description' => 'Estás ejecutando contra el proveedor de KYC simulado. Elige un resultado para simular.',
        'success' => 'Completar verificación (éxito)',
        'fail' => 'Completar verificación (fallo)',
        'cancel' => 'Cancelar verificación',
    ],
    'esign' => [
        'title' => 'Sobre de firma electrónica simulado',
        'description' => 'Estás ejecutando contra el proveedor de firma electrónica simulado. Elige un resultado para simular.',
        'success' => 'Firmar el sobre',
        'fail' => 'Rechazar el sobre',
        'cancel' => 'Cancelar la firma',
    ],
    'stripe' => [
        'title' => 'Onboarding de Stripe Connect simulado',
        'description' => 'Estás ejecutando contra el proveedor de pagos simulado. Elige un resultado para simular.',
        'success' => 'Completar onboarding',
        'fail' => 'Cancelar onboarding',
    ],
    'session_unknown' => 'Sesión desconocida o caducada.',
];
