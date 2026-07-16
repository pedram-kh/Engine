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
    'connection_request' => [
        'email' => [
            'subject' => ':agency quer conectar-se consigo no Catalyst',
            'greeting' => 'Olá :name,',
            'body' => ':agency gostaria de adicioná-lo ao plantel deles no Catalyst. Abra o seu painel para aceitar ou recusar o pedido.',
            'cta' => 'Ver o pedido',
            'ignore' => 'Se não reconhece esta agência, pode simplesmente recusar — nada muda até que aceite.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Conclua a configuração da sua conta Catalyst',
            'greeting' => 'Olá :name,',
            'body' => 'Começou a criar a sua conta de criador na Catalyst, mas ainda não confirmou o seu endereço de e-mail. Confirme-o agora para retomar de onde parou e concluir o seu registo.',
            'cta' => 'Confirmar o meu e-mail',
            'expiry' => 'Esta ligação expira em :hours horas. Se expirar, pode solicitar uma nova na página de início de sessão.',
            'ignore' => 'Se não iniciou este registo, pode ignorar este e-mail em segurança.',
        ],
        'finish' => [
            'subject' => 'Conclua a configuração do seu perfil de criador na Catalyst',
            'greeting' => 'Olá :name,',
            'body' => 'Começou a configurar o seu perfil de criador na Catalyst, mas ainda não o concluiu. Retome de onde parou para concluir o seu registo.',
            'cta' => 'Concluir o meu perfil',
            'ignore' => 'Se já concluiu o seu perfil, pode ignorar este e-mail em segurança.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency atualizou o seu estado de colaboração no Catalyst',
            'greeting' => 'Olá :name,',
            'body' => ':agency atualizou o seu estado de colaboração no Catalyst. Se tiver alguma dúvida, contacte-os diretamente.',
            'closing' => 'Obrigado por fazer parte do Catalyst.',
        ],
    ],
];
