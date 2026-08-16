<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Entwurf :n',
        'round_subject' => ':subject (:round)',
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator hat einen Entwurf zur Überprüfung eingereicht',
                'greeting' => 'Hallo :name,',
                'body' => ':creator hat einen Entwurf für „:campaign" eingereicht. Öffne die Kampagne, um ihn zu genehmigen, Änderungen anzufordern oder abzulehnen.',
                'cta' => 'Entwurf prüfen',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Dein Entwurf für :campaign wurde genehmigt',
                'subject_revision_requested' => 'Änderungen an deinem :campaign-Entwurf angefordert',
                'subject_rejected' => 'Eine Aktualisierung zu deinem :campaign-Entwurf',
                'greeting' => 'Hallo :name,',
                'body_approved' => 'Gute Neuigkeiten – dein Entwurf für „:campaign" wurde genehmigt. Du kannst ihn jetzt veröffentlichen und den Live-Link einreichen.',
                'body_revision_requested' => 'Die Agentur hat Änderungen an deinem Entwurf für „:campaign" angefordert. Überprüfe das Feedback unten und reiche ihn erneut ein.',
                'body_rejected' => 'Nach der Überprüfung wurde dein Entwurf für „:campaign" nicht akzeptiert und der Auftrag wurde geschlossen.',
                'feedback_label' => 'Feedback',
                'cta' => 'Auftrag ansehen',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Post für :campaign konnte nicht verifiziert werden',
                'greeting' => 'Hallo :name,',
                'body' => 'Wir konnten den Post von :creator für „:campaign" nicht automatisch verifizieren. Bitte überprüfe den eingereichten Link.',
                'reason_label' => 'Was ist passiert',
                'reason_not_found' => 'Der Post konnte unter dem eingereichten Link nicht gefunden werden.',
                'reason_mismatch' => 'Der Post unter dem eingereichten Link scheint nicht zum verbundenen Konto des Creators zu gehören.',
                'cta' => 'Auftrag prüfen',
            ],
        ],
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Ihre Arbeit für :campaign ist abgeschlossen',
                'greeting' => 'Hallo :name,',
                'body' => 'Ihr Entwurf für „:campaign“ wurde freigegeben. Bei dieser Kampagne veröffentlicht die Agentur den Inhalt, Ihr Auftrag ist damit abgeschlossen — es ist nichts weiter zu tun.',
                'cta' => 'Auftrag ansehen',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Dein Post für :campaign wurde akzeptiert',
                'greeting' => 'Hallo :name,',
                'body' => 'Gute Neuigkeiten – die Agentur hat deinen Post für „:campaign" überprüft und akzeptiert. Es sind keine weiteren Maßnahmen erforderlich.',
                'cta' => 'Auftrag ansehen',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Handlungsbedarf bei deinem :campaign-Post',
                'greeting' => 'Hallo :name,',
                'body_fresh' => 'Die Agentur konnte deinen Post für „:campaign" nicht verifizieren und bittet dich, einen neuen Post-Link einzureichen. Öffne den Auftrag, um ihn erneut einzureichen.',
                'body_in_place' => 'Die Agentur konnte deinen Post für „:campaign" nicht verifizieren und bittet dich, den eingereichten Link zu korrigieren. Öffne den Auftrag, um ihn zu aktualisieren.',
                'feedback_label' => 'Hinweis der Agentur',
                'cta' => 'Auftrag öffnen',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Vertrag für :campaign bereit',
                'greeting' => 'Hallo :name,',
                'body' => 'Ein Vertrag für „:campaign" steht zur Überprüfung bereit. Öffne den Auftrag, um die Bedingungen zu lesen und zu akzeptieren.',
                'cta' => 'Vertrag prüfen',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator hat den Vertrag akzeptiert',
                'greeting' => 'Hallo :name,',
                'body' => ':creator hat den Vertrag für „:campaign" akzeptiert. Er kann jetzt mit der Arbeit an seinem Entwurf beginnen.',
                'cta' => 'Kampagne ansehen',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency hat einen neuen Job veröffentlicht',
        'greeting' => 'Hallo :name,',
        'body' => ':agency hat einen neuen Job auf deinem Board veröffentlicht: „:campaign“. Öffne ihn, um die Details zu sehen und dich zu bewerben.',
        'cta' => 'Job ansehen',
        'ignore' => 'Du erhältst diese Nachricht, weil du auf der Creator-Liste von :agency stehst.',
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
            'subject' => 'Neue Bewerbung für :campaign',
            'greeting' => 'Hallo :name,',
            'body' => ':creator hat sich auf „:campaign“ beworben. Öffne die Kampagne, um die Bewerbung zu prüfen und ein Angebot zu senden.',
            'cta' => 'Bewerbung ansehen',
        ],
        'accepted' => [
            'subject' => 'Deine Bewerbung für :campaign wurde angenommen',
            'greeting' => 'Hallo :name,',
            'body' => ':agency hat deine Bewerbung für „:campaign“ angenommen und dir ein Angebot gesendet. Öffne den Auftrag, prüfe die Konditionen und nimm sie an oder lehne sie ab.',
            'cta' => 'Angebot ansehen',
        ],
        'rejected' => [
            'subject' => 'Neues zu deiner Bewerbung für :campaign',
            'greeting' => 'Hallo :name,',
            'body_agency_rejected' => 'Danke für deine Bewerbung auf „:campaign“. Du wurdest für diesen Job nicht ausgewählt. Neue Jobs erscheinen regelmäßig auf deinem Board.',
            'body_campaign_closed' => 'Danke für deine Bewerbung auf „:campaign“. Die Kampagne wurde geschlossen, deine Bewerbung wird daher nicht weiter berücksichtigt. Neue Jobs erscheinen regelmäßig auf deinem Board.',
            'cta' => 'Jobbörse ansehen',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators hat noch eine Karte in der Veröffentlichungsspalte. Verschieben Sie die Karte aus der Spalte, bevor Sie das Veröffentlichen durch Creator deaktivieren.|[2,*] :count Karten befinden sich noch in der Veröffentlichungsspalte (:creators). Verschieben Sie sie aus der Spalte, bevor Sie das Veröffentlichen durch Creator deaktivieren.',
    ],
];
