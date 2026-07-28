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
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency ha pubblicato un nuovo lavoro',
        'greeting' => 'Ciao :name,',
        'body' => ':agency ha pubblicato un nuovo lavoro nella tua bacheca: «:campaign». Aprilo per vedere i dettagli e candidarti.',
        'cta' => 'Vedi il lavoro',
        'ignore' => 'Ricevi questo messaggio perché fai parte dell’elenco di creator di :agency.',
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
            'subject' => 'Nuova candidatura per :campaign',
            'greeting' => 'Ciao :name,',
            'body' => ':creator si è candidato a «:campaign». Apri la campagna per esaminare la candidatura e inviare un\'offerta.',
            'cta' => 'Esamina la candidatura',
        ],
        'accepted' => [
            'subject' => 'La tua candidatura per :campaign è stata accettata',
            'greeting' => 'Ciao :name,',
            'body' => ':agency ha accettato la tua candidatura per «:campaign» e ti ha inviato un\'offerta. Apri l\'incarico per leggere le condizioni e accettarla o rifiutarla.',
            'cta' => 'Vedi l\'offerta',
        ],
        'rejected' => [
            'subject' => 'Aggiornamento sulla tua candidatura per :campaign',
            'greeting' => 'Ciao :name,',
            'body_agency_rejected' => 'Grazie per aver inviato la tua candidatura per «:campaign». Non sei stato selezionato per questo lavoro. Nuovi lavori vengono pubblicati regolarmente sulla tua bacheca.',
            'body_campaign_closed' => 'Grazie per aver inviato la tua candidatura per «:campaign». La campagna è stata chiusa, quindi la tua candidatura non proseguirà. Nuovi lavori vengono pubblicati regolarmente sulla tua bacheca.',
            'cta' => 'Vedi la bacheca lavori',
        ],
    ],
];
