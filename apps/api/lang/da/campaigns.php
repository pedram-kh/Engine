<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Udkast :n',
        'round_subject' => ':subject (:round)',
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
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Dit arbejde til :campaign er færdigt',
                'greeting' => 'Hej :name,',
                'body' => 'Din kladde til ":campaign" er blevet godkendt. På denne kampagne offentliggør bureauet indholdet, så din opgave er nu afsluttet — du skal ikke gøre mere.',
                'cta' => 'Se opgave',
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
        'invite_received' => [
            'email' => [
                'subject_fresh' => 'Du har et nyt tilbud til :campaign',
                'subject_re_offer' => 'Opdateret tilbud til :campaign',
                'greeting' => 'Hej :name,',
                'body_fresh' => 'Du er blevet inviteret til at arbejde på ":campaign". Åbn opgaven for at gennemgå tilbuddet og svare.',
                'body_re_offer' => 'Et opdateret tilbud venter på dig på ":campaign". Åbn opgaven for at gennemgå det og svare.',
                'cta' => 'Se tilbuddet',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency har slået et nyt job op',
        'greeting' => 'Hej :name,',
        'body' => ':agency har slået et nyt job op på din tavle: ":campaign". Åbn det for at se detaljerne og ansøge.',
        'cta' => 'Se jobbet',
        'ignore' => 'Du modtager denne besked, fordi du står på kreatørlisten hos :agency.',
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
            'subject' => 'Ny ansøgning til :campaign',
            'greeting' => 'Hej :name,',
            'body' => ':creator har søgt »:campaign«. Åbn kampagnen for at se ansøgningen og sende et tilbud.',
            'cta' => 'Se ansøgningen',
        ],
        'accepted' => [
            'subject' => 'Din ansøgning til :campaign er accepteret',
            'greeting' => 'Hej :name,',
            'body' => ':agency har accepteret din ansøgning til »:campaign« og sendt dig et tilbud. Åbn opgaven for at se vilkårene og acceptere eller afvise.',
            'cta' => 'Se tilbuddet',
        ],
        'rejected' => [
            'subject' => 'Nyt om din ansøgning til :campaign',
            'greeting' => 'Hej :name,',
            'body_agency_rejected' => 'Tak for din ansøgning til »:campaign«. Du blev ikke valgt til dette job. Der kommer løbende nye jobopslag på dit board.',
            'body_campaign_closed' => 'Tak for din ansøgning til »:campaign«. Kampagnen er lukket, så din ansøgning går ikke videre. Der kommer løbende nye jobopslag på dit board.',
            'cta' => 'Se jobopslag',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators har stadig et kort i udgivelseskolonnen. Flyt kortet ud af kolonnen, før du slår udgivelse fra skabere fra.|[2,*] :count kort er stadig i udgivelseskolonnen (:creators). Flyt dem ud af kolonnen, før du slår udgivelse fra skabere fra.',
    ],
];
