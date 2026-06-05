<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator enviou um rascunho para revisão',
                'greeting' => 'Olá :name,',
                'body' => ':creator enviou um rascunho para ":campaign". Abra a campanha para aprovar, solicitar alterações ou rejeitar.',
                'cta' => 'Revisar o rascunho',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'O seu rascunho para :campaign foi aprovado',
                'subject_revision_requested' => 'Alterações solicitadas no seu rascunho de :campaign',
                'subject_rejected' => 'Uma atualização sobre o seu rascunho de :campaign',
                'greeting' => 'Olá :name,',
                'body_approved' => 'Boas notícias — o seu rascunho para ":campaign" foi aprovado. Já pode publicá-lo e enviar o link da publicação.',
                'body_revision_requested' => 'A agência solicitou alterações ao seu rascunho para ":campaign". Reveja o feedback abaixo e reenvie.',
                'body_rejected' => 'Após a revisão, o seu rascunho para ":campaign" não foi aceite e a tarefa foi encerrada.',
                'feedback_label' => 'Feedback',
                'cta' => 'Ver a tarefa',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Não foi possível verificar a publicação de :campaign',
                'greeting' => 'Olá :name,',
                'body' => 'Não foi possível verificar automaticamente a publicação de :creator para ":campaign". Reveja o link enviado.',
                'reason_label' => 'O que aconteceu',
                'reason_not_found' => 'A publicação não foi encontrada no link enviado.',
                'reason_mismatch' => 'A publicação no link enviado não parece pertencer à conta associada do criador.',
                'cta' => 'Rever a tarefa',
            ],
        ],
    ],
];
