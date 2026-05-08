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
];
