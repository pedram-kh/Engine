<?php

declare(strict_types=1);

return [
    'kyc' => [
        'title' => 'Verificação KYC simulada',
        'description' => 'Você está conectado ao provedor KYC simulado. Escolha um resultado para simular.',
        'success' => 'Concluir verificação (sucesso)',
        'fail' => 'Concluir verificação (falha)',
        'cancel' => 'Cancelar verificação',
    ],
    'esign' => [
        'title' => 'Envelope de assinatura simulado',
        'description' => 'Você está conectado ao provedor de assinatura simulado. Escolha um resultado para simular.',
        'success' => 'Assinar envelope',
        'fail' => 'Recusar envelope',
        'cancel' => 'Cancelar assinatura',
    ],
    'stripe' => [
        'title' => 'Onboarding Stripe Connect simulado',
        'description' => 'Você está conectado ao provedor de pagamento simulado. Escolha um resultado para simular.',
        'success' => 'Concluir onboarding',
        'fail' => 'Cancelar onboarding',
    ],
    'session_unknown' => 'Sessão desconhecida ou expirada.',
];
