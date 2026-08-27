<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'A palavra-passe deve ser uma cadeia de caracteres.',
        'too_short' => 'A palavra-passe deve ter pelo menos :min caracteres.',
        'too_long' => 'A palavra-passe não deve exceder :max caracteres.',
        'breached' => 'Esta palavra-passe aparece em violações de dados conhecidas e não pode ser usada. Escolha uma diferente.',
    ],

    'login' => [
        'invalid_credentials' => 'E-mail ou palavra-passe inválidos.',
        'mfa_required' => 'É necessária a autenticação multifator para concluir o início de sessão.',
        'account_locked_temporary' => 'Demasiadas tentativas falhadas. Tente novamente em :minutes minutos.',
        'account_locked' => 'Esta conta foi bloqueada. Redefina a palavra-passe ou contacte o suporte para recuperar o acesso.',
        'rate_limited' => 'Demasiados pedidos. Tente novamente em :seconds segundos.',
        'wrong_spa' => 'Esta conta não está registada para este site. Inicie sessão no site correto.',
    ],

    'reset' => [
        'subject' => 'Redefinir a sua palavra-passe :app',
        'greeting' => 'Olá :name,',
        'body' => 'Recebemos um pedido para redefinir a palavra-passe da sua conta :app. A ligação abaixo é válida durante :minutes minutos.',
        'cta' => 'Redefinir palavra-passe',
        'ignore' => 'Se não solicitou esta alteração, pode ignorar este e-mail — a sua palavra-passe não será alterada.',
        'invalid_token' => 'Esta ligação de redefinição de palavra-passe é inválida ou expirou. Solicite uma nova.',
        'completed' => 'A sua palavra-passe foi redefinida. Todas as outras sessões ativas foram terminadas.',
    ],

    'email_verification' => [
        'subject' => 'Confirme o seu endereço de e-mail :app',
        'greeting' => 'Bem-vindo ao :app, :name!',
        'body' => 'Por favor, confirme o seu endereço de e-mail para concluir a configuração da sua conta :app. A ligação abaixo é válida durante :hours horas.',
        'cta' => 'Confirmar endereço de e-mail',
        'ignore' => 'Se não criou uma conta :app, pode ignorar este e-mail.',
        'verification_invalid' => 'Esta ligação de verificação é inválida. Solicite uma nova.',
        'verification_expired' => 'Esta ligação de verificação expirou. Solicite uma nova.',
        'already_verified' => 'Este endereço de e-mail já foi confirmado.',
    ],

    'signup' => [
        'email_taken' => 'Já existe uma conta com este endereço de e-mail.',
    ],

    'mfa' => [
        'invalid_code' => 'O código de dois fatores é inválido. Tente novamente.',
        'rate_limited' => 'Demasiadas tentativas inválidas de dois fatores. Tente novamente em :minutes minutos.',
        'enrollment_suspended' => 'A autenticação de dois fatores foi suspensa nesta conta. Contacte o suporte para restaurar o acesso.',
        'enrollment_required' => 'A autenticação de dois fatores deve ser ativada antes de poder continuar.',
        'already_enabled' => 'A autenticação de dois fatores já está ativada nesta conta.',
        'not_enabled' => 'A autenticação de dois fatores não está ativada nesta conta.',
        'provisional_expired' => 'A sessão de inscrição em dois fatores expirou. Comece novamente.',
    ],

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'A sua autenticação de dois fatores foi reiniciada',
            'greeting' => 'Olá :name,',
            'body' => 'Um administrador da plataforma reiniciou a autenticação de dois fatores da sua conta :app. A sua aplicação de autenticação anterior e os códigos de recuperação já não funcionam.',
            'next' => 'Será pedido que configure novamente a autenticação de dois fatores no próximo acesso a uma parte protegida da aplicação.',
            'unexpected' => 'Se não pediu isto, contacte imediatamente o suporte.',
            'cta' => 'Ir para :app',
        ],
        'exempted' => [
            'subject' => 'A autenticação de dois fatores já não é obrigatória na sua conta',
            'greeting' => 'Olá :name,',
            'body' => 'Um administrador da plataforma removeu a obrigação de autenticação de dois fatores da sua conta :app. Já pode usar sem a configurar as áreas que antes lha pediam.',
            'still_enrolled' => 'Se já tem a autenticação de dois fatores configurada, continua ativa e continuará a ser-lhe pedido um código ao iniciar sessão — pode mantê-la ou desativá-la nas suas definições.',
            'unexpected' => 'Se não esperava esta alteração, contacte o suporte.',
        ],
        'exemption_removed' => [
            'subject' => 'A autenticação de dois fatores é novamente obrigatória na sua conta',
            'greeting' => 'Olá :name,',
            'body' => 'Um administrador da plataforma restaurou a obrigação de autenticação de dois fatores na sua conta :app.',
            'next' => 'No próximo acesso a uma área protegida será pedido que configure a autenticação de dois fatores, caso ainda não o tenha feito.',
            'unexpected' => 'Se não esperava esta alteração, contacte o suporte.',
        ],
    ],
];
