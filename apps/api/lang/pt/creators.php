<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Você foi convidado para :agency no Catalyst',
            'greeting' => 'Olá,',
            'body' => ':agency convidou você para se juntar ao plantel deles no Catalyst. Clique no botão abaixo para configurar o seu perfil de criador.',
            'cta' => 'Começar',
            'expiry' => 'Este convite expira em :date.',
            'ignore' => 'Se você não esperava este convite, pode ignorar este e-mail.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'A sua candidatura ao Catalyst foi aprovada',
            'greeting' => 'Olá :name,',
            'body' => 'Boas notícias — a sua candidatura de criador foi aprovada. Agora tem acesso total ao seu painel do Catalyst.',
            'cta' => 'Ir para o seu painel',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Uma atualização sobre a sua candidatura ao Catalyst',
            'greeting' => 'Olá :name,',
            'body' => 'Obrigado por se candidatar ao Catalyst. Após análise, não podemos aprovar a sua candidatura neste momento.',
            'reason_label' => 'Motivo',
            'resubmit_hint' => 'Pode atualizar a sua candidatura e submetê-la novamente para nova análise a partir do seu painel.',
            'cta' => 'Rever a sua candidatura',
        ],
    ],
];
