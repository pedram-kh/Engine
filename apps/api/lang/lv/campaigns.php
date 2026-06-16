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
];
