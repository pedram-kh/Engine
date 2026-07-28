<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator iesniedza melnrakstu pārskatīšanai',
                'greeting' => 'Sveiki, :name,',
                'body' => ':creator iesniedza melnrakstu priekš ":campaign". Atveriet kampaņu un apstipriniet to, pieprasiet izmaiņas vai noraidiet.',
                'cta' => 'Pārskatīt melnrakstu',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Jūsu melnraksts priekš :campaign ir apstiprināts',
                'subject_revision_requested' => 'Pieprasītas izmaiņas jūsu melnrakstam priekš :campaign',
                'subject_rejected' => 'Atjauninājums par jūsu melnrakstu priekš :campaign',
                'greeting' => 'Sveiki, :name,',
                'body_approved' => 'Lieliskas ziņas — jūsu melnraksts priekš ":campaign" ir apstiprināts. Tagad varat publicēt un nosūtīt tiešraides saiti.',
                'body_revision_requested' => 'Aģentūra pieprasa izmaiņas jūsu melnrakstam priekš ":campaign". Apskatiet zemāk esošo atgriezenisko saiti un iesniedziet vēlreiz.',
                'body_rejected' => 'Pēc pārskatīšanas jūsu melnraksts priekš ":campaign" nav pieņemts un uzdevums ir slēgts.',
                'feedback_label' => 'Atgriezeniskā saite',
                'cta' => 'Skatīt uzdevumu',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Ieraksta verifikācija priekš :campaign neizdevās',
                'greeting' => 'Sveiki, :name,',
                'body' => 'Mēs nevarējām automātiski verificēt :creator ierakstu priekš ":campaign". Pārskatiet iesniegto saiti.',
                'reason_label' => 'Kas notika',
                'reason_not_found' => 'Ieraksts nav atrasts iesniegtajā saitē.',
                'reason_mismatch' => 'Šķiet, ka ieraksts iesniegtajā saitē nepieder radītāja pievienotajam kontam.',
                'cta' => 'Pārskatīt uzdevumu',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Jūsu ieraksts priekš :campaign ir pieņemts',
                'greeting' => 'Sveiki, :name,',
                'body' => 'Lieliskas ziņas — aģentūra ir pārskatījusi un pieņēmusi jūsu ierakstu priekš ":campaign". Nav nepieciešamas turpmākas darbības.',
                'cta' => 'Skatīt uzdevumu',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Nepieciešama darbība jūsu ierakstam priekš :campaign',
                'greeting' => 'Sveiki, :name,',
                'body_fresh' => 'Aģentūra nevarēja verificēt jūsu ierakstu priekš ":campaign" un pieprasa iesniegt jaunu saiti. Atveriet uzdevumu un iesniedziet vēlreiz.',
                'body_in_place' => 'Aģentūra nevarēja verificēt jūsu ierakstu priekš ":campaign" un pieprasa labot iesniegto saiti. Atveriet uzdevumu un atjauniniet to.',
                'feedback_label' => 'Piezīme no aģentūras',
                'cta' => 'Atvērt uzdevumu',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Līgums priekš :campaign ir gatavs',
                'greeting' => 'Sveiki, :name,',
                'body' => 'Līgums priekš ":campaign" ir gatavs jūsu pārskatīšanai. Atveriet uzdevumu, izlasiet nosacījumus un pieņemiet tos.',
                'cta' => 'Pārskatīt līgumu',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator pieņēma līgumu',
                'greeting' => 'Sveiki, :name,',
                'body' => ':creator pieņēma līgumu priekš ":campaign". Viņi tagad var sākt strādāt pie sava melnraksta.',
                'cta' => 'Skatīt kampaņu',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency publicēja jaunu darba piedāvājumu',
        'greeting' => 'Sveiki, :name,',
        'body' => ':agency jūsu panelī publicēja jaunu darba piedāvājumu: “:campaign”. Atveriet to, lai skatītu informāciju un pieteiktos.',
        'cta' => 'Skatīt piedāvājumu',
        'ignore' => 'Jūs saņemat šo ziņu, jo esat :agency autoru sarakstā.',
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
            'subject' => 'Jauns pieteikums darbam :campaign',
            'greeting' => 'Sveiki, :name,',
            'body' => ':creator pieteicās darbam „:campaign“. Atveriet kampaņu, izskatiet pieteikumu un nosūtiet piedāvājumu.',
            'cta' => 'Skatīt pieteikumu',
        ],
        'accepted' => [
            'subject' => 'Jūsu pieteikums darbam :campaign ir pieņemts',
            'greeting' => 'Sveiki, :name,',
            'body' => ':agency pieņēma jūsu pieteikumu darbam „:campaign“ un nosūtīja jums piedāvājumu. Atveriet uzdevumu, izskatiet nosacījumus un pieņemiet vai atsakiet piedāvājumu.',
            'cta' => 'Skatīt piedāvājumu',
        ],
        'rejected' => [
            'subject' => 'Jaunumi par jūsu pieteikumu darbam :campaign',
            'greeting' => 'Sveiki, :name,',
            'body_agency_rejected' => 'Paldies, ka pieteicāties darbam „:campaign“. Šim darbam jūs netikāt izraudzīts. Jauni darba piedāvājumi jūsu panelī tiek publicēti regulāri.',
            'body_campaign_closed' => 'Paldies, ka pieteicāties darbam „:campaign“. Kampaņa ir noslēgta, tāpēc jūsu pieteikums netiks izskatīts tālāk. Jauni darba piedāvājumi jūsu panelī tiek publicēti regulāri.',
            'cta' => 'Skatīt darba piedāvājumus',
        ],
    ],
];
