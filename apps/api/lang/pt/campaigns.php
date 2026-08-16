<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Rascunho :n',
        'round_subject' => ':subject (:round)',
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
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => 'A :agency publicou um novo trabalho',
        'greeting' => 'Olá :name,',
        'body' => 'A :agency publicou um novo trabalho no seu quadro: «:campaign». Abra-o para ver os detalhes e candidatar-se.',
        'cta' => 'Ver o trabalho',
        'ignore' => 'Está a receber esta mensagem porque faz parte da lista de criadores da :agency.',
    ],
    // AH-058 (Jobs Board chunk 4, D6) — the three application mails. All three
    // are queued and localized at queue time to the recipient's
    // preferred_language (a worker has no request locale), and all three are
    // gated by the `application_notifications_enabled` Pennant flag on the MAIL
    // leg only — the in-app rows write regardless.
    //
    // `rejected` carries TWO body variants selected by
    // ApplicationRejectionCause (`body_agency_rejected` / `body_campaign_closed`)
    // under ONE subject, the draft-reviewed `body_ . $outcome` precedent: the
    // recipient's question is the same either way, and two mailables would double
    // 24 locales of copy to express one sentence of difference.
    //
    // ⚠ No agency-supplied reason exists anywhere in the reject copy, by design
    // (D4): none is collected or stored, and the audit row plus its actor is the
    // internal record.
    'campaign_application' => [
        'submitted' => [
            'subject' => 'Nova candidatura para :campaign',
            'greeting' => 'Olá :name,',
            'body' => ':creator candidatou-se a «:campaign». Abra a campanha para analisar a candidatura e enviar uma proposta.',
            'cta' => 'Analisar a candidatura',
        ],
        'accepted' => [
            'subject' => 'A sua candidatura a :campaign foi aceite',
            'greeting' => 'Olá :name,',
            'body' => 'A :agency aceitou a sua candidatura a «:campaign» e enviou-lhe uma proposta. Abra a tarefa para consultar as condições e aceitar ou recusar.',
            'cta' => 'Ver a proposta',
        ],
        'rejected' => [
            'subject' => 'Novidades sobre a sua candidatura a :campaign',
            'greeting' => 'Olá :name,',
            'body_agency_rejected' => 'Obrigado pela sua candidatura a «:campaign». Não foi selecionado para este trabalho. São publicados novos trabalhos no seu painel com regularidade.',
            'body_campaign_closed' => 'Obrigado pela sua candidatura a «:campaign». A campanha foi encerrada, pelo que a sua candidatura não seguirá em frente. São publicados novos trabalhos no seu painel com regularidade.',
            'cta' => 'Ver as ofertas de trabalho',
        ],
    ],
];
