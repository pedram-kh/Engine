<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator ha inviato una bozza per la revisione',
                'greeting' => 'Ciao :name,',
                'body' => ':creator ha inviato una bozza per ":campaign". Apri la campagna per approvarla, richiedere modifiche o rifiutarla.',
                'cta' => 'Rivedi la bozza',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'La tua bozza per :campaign è stata approvata',
                'subject_revision_requested' => 'Modifiche richieste sulla tua bozza di :campaign',
                'subject_rejected' => 'Un aggiornamento sulla tua bozza di :campaign',
                'greeting' => 'Ciao :name,',
                'body_approved' => 'Buone notizie — la tua bozza per ":campaign" è stata approvata. Ora puoi pubblicarla e inviare il link del post.',
                'body_revision_requested' => 'L\'agenzia ha richiesto modifiche alla tua bozza per ":campaign". Rivedi il feedback qui sotto e reinvia.',
                'body_rejected' => 'Dopo la revisione, la tua bozza per ":campaign" non è stata accettata e l\'incarico è stato chiuso.',
                'feedback_label' => 'Feedback',
                'cta' => 'Vedi l\'incarico',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Impossibile verificare il post per :campaign',
                'greeting' => 'Ciao :name,',
                'body' => 'Non è stato possibile verificare automaticamente il post di :creator per ":campaign". Controlla il link inviato.',
                'reason_label' => 'Cosa è successo',
                'reason_not_found' => 'Il post non è stato trovato al link inviato.',
                'reason_mismatch' => 'Il post al link inviato non sembra appartenere all\'account collegato del creator.',
                'cta' => 'Rivedi l\'incarico',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Il tuo post per :campaign è stato accettato',
                'greeting' => 'Ciao :name,',
                'body' => 'Buone notizie — l\'agenzia ha rivisto e accettato il tuo post per ":campaign". Non è necessaria alcuna ulteriore azione.',
                'cta' => 'Vedi l\'incarico',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Azione richiesta sul tuo post per :campaign',
                'greeting' => 'Ciao :name,',
                'body_fresh' => 'L\'agenzia non è riuscita a verificare il tuo post per ":campaign" e ti ha chiesto di inviare un nuovo link al post. Apri l\'incarico per reinviare.',
                'body_in_place' => 'L\'agenzia non è riuscita a verificare il tuo post per ":campaign" e ti ha chiesto di correggere il link inviato. Apri l\'incarico per aggiornarlo.',
                'feedback_label' => 'Nota dall\'agenzia',
                'cta' => 'Apri l\'incarico',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Contratto pronto per :campaign',
                'greeting' => 'Ciao :name,',
                'body' => 'Un contratto per ":campaign" è pronto per la revisione. Apri l\'incarico per leggere i condizioni e accettare.',
                'cta' => 'Rivedi il contrato',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator ha accettato il contrato',
                'greeting' => 'Ciao :name,',
                'body' => ':creator ha accettato il contrato per ":campaign". Ora può iniziare a lavorare sulla bozza.',
                'cta' => 'Vedi la campagna',
            ],
        ],
    ],
];
