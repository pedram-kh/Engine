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
        'manually_verified' => [
            'email' => [
                'subject' => 'A sua publicação para :campaign foi aceite',
                'greeting' => 'Olá :name,',
                'body' => 'Boas notícias — a agência reviu e aceitou a sua publicação para ":campaign". Não é necessária nenhuma ação adicional.',
                'cta' => 'Ver a tarefa',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Ação necessária na sua publicação de :campaign',
                'greeting' => 'Olá :name,',
                'body_fresh' => 'A agência não conseguiu verificar a sua publicação para ":campaign" e pediu que envie um novo link de publicação. Abra a tarefa para reenviar.',
                'body_in_place' => 'A agência não conseguiu verificar a sua publicação para ":campaign" e pediu que corrija o link enviado. Abra a tarefa para atualizá-lo.',
                'feedback_label' => 'Nota da agência',
                'cta' => 'Abrir a tarefa',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Contrato pronto para :campaign',
                'greeting' => 'Olá :name,',
                'body' => 'Um contrato para ":campaign" está pronto para revisão. Abra a tarefa para ler os termos e aceitar.',
                'cta' => 'Rever o contrato',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator aceitou o contrato',
                'greeting' => 'Olá :name,',
                'body' => ':creator aceitou o contrato para ":campaign". Já pode começar a trabalhar no rascunho.',
                'cta' => 'Ver a campanha',
            ],
        ],
    ],
];
