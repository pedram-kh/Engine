<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator har indsendt et udkast til gennemgang',
                'greeting' => 'Hej :name,',
                'body' => ':creator har indsendt et udkast til ":campaign". Åbn kampagnen for at godkende det, anmode om ændringer eller afvise det.',
                'cta' => 'Gennemse udkast',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Dit udkast til :campaign er godkendt',
                'subject_revision_requested' => 'Ændringer anmodet til dit :campaign-udkast',
                'subject_rejected' => 'En opdatering om dit :campaign-udkast',
                'greeting' => 'Hej :name,',
                'body_approved' => 'Gode nyheder — dit udkast til ":campaign" er godkendt. Du kan nu publicere det og indsende det live-link.',
                'body_revision_requested' => 'Bureauet har anmodet om ændringer til dit udkast til ":campaign". Gennemgå feedbacken nedenfor og indsend det igen.',
                'body_rejected' => 'Efter gennemgang er dit udkast til ":campaign" ikke accepteret og opgaven er afsluttet.',
                'feedback_label' => 'Feedback',
                'cta' => 'Se opgave',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Opslaget for :campaign kunne ikke verificeres',
                'greeting' => 'Hej :name,',
                'body' => 'Vi kunne ikke automatisk verificere :creator\'s opslag for ":campaign". Gennemgå venligst det indsendte link.',
                'reason_label' => 'Hvad skete der',
                'reason_not_found' => 'Opslaget kunne ikke findes via det indsendte link.',
                'reason_mismatch' => 'Opslaget via det indsendte link ser ikke ud til at tilhøre creatorens tilknyttede konto.',
                'cta' => 'Gennemse opgave',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Dit opslag for :campaign er accepteret',
                'greeting' => 'Hej :name,',
                'body' => 'Gode nyheder — bureauet har gennemgået og accepteret dit opslag for ":campaign". Ingen yderligere handling er nødvendig.',
                'cta' => 'Se opgave',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Handling påkrævet for dit :campaign-opslag',
                'greeting' => 'Hej :name,',
                'body_fresh' => 'Bureauet kunne ikke verificere dit opslag for ":campaign" og beder dig indsende et nyt opslagslink. Åbn opgaven for at indsende igen.',
                'body_in_place' => 'Bureauet kunne ikke verificere dit opslag for ":campaign" og beder dig rette det indsendte link. Åbn opgaven for at opdatere det.',
                'feedback_label' => 'Bureauets bemærkning',
                'cta' => 'Åbn opgave',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Kontrakt til :campaign er klar',
                'greeting' => 'Hej :name,',
                'body' => 'En kontrakt til ":campaign" er klar til gennemgang. Åbn opgaven for at læse vilkårene og acceptere dem.',
                'cta' => 'Gennemse kontrakt',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator har accepteret kontrakten',
                'greeting' => 'Hej :name,',
                'body' => ':creator har accepteret kontrakten til ":campaign". De kan nu begynde at arbejde på deres udkast.',
                'cta' => 'Se kampagne',
            ],
        ],
    ],
];
