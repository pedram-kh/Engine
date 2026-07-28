<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator skickade in ett utkast för granskning',
                'greeting' => 'Hej :name,',
                'body' => ':creator har skickat in ett utkast för ":campaign". Öppna kampanjen för att godkänna det, begära ändringar eller avvisa det.',
                'cta' => 'Granska utkastet',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Ditt utkast för :campaign godkändes',
                'subject_revision_requested' => 'Ändringar begärda för ditt :campaign-utkast',
                'subject_rejected' => 'En uppdatering om ditt :campaign-utkast',
                'greeting' => 'Hej :name,',
                'body_approved' => 'Bra nyheter — ditt utkast för ":campaign" godkändes. Du kan nu publicera det och skicka in den live-länken.',
                'body_revision_requested' => 'Byrån har begärt ändringar för ditt utkast till ":campaign". Granska feedbacken nedan och skicka in på nytt.',
                'body_rejected' => 'Efter granskning accepterades inte ditt utkast för ":campaign" och uppdraget har avslutats.',
                'feedback_label' => 'Feedback',
                'cta' => 'Visa uppdraget',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Kunde inte verifiera inlägget för :campaign',
                'greeting' => 'Hej :name,',
                'body' => 'Vi kunde inte automatiskt verifiera :creator\'s inlägg för ":campaign". Granska den inskickade länken.',
                'reason_label' => 'Vad hände',
                'reason_not_found' => 'Inlägget kunde inte hittas på den inskickade länken.',
                'reason_mismatch' => 'Inlägget på den inskickade länken verkar inte tillhöra creatorns anslutna konto.',
                'cta' => 'Granska uppdraget',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Ditt inlägg för :campaign accepterades',
                'greeting' => 'Hej :name,',
                'body' => 'Bra nyheter — byrån har granskat och accepterat ditt inlägg för ":campaign". Ingen ytterligare åtgärd krävs.',
                'cta' => 'Visa uppdraget',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Åtgärd krävs för ditt :campaign-inlägg',
                'greeting' => 'Hej :name,',
                'body_fresh' => 'Byrån kunde inte verifiera ditt inlägg för ":campaign" och ber dig skicka in en ny inläggslänk. Öppna uppdraget för att skicka in igen.',
                'body_in_place' => 'Byrån kunde inte verifiera ditt inlägg för ":campaign" och ber dig rätta den inskickade länken. Öppna uppdraget för att uppdatera den.',
                'feedback_label' => 'Notering från byrån',
                'cta' => 'Öppna uppdraget',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Kontrakt klart för :campaign',
                'greeting' => 'Hej :name,',
                'body' => 'Ett kontrakt för ":campaign" är klart för din granskning. Öppna uppdraget för att läsa villkoren och acceptera.',
                'cta' => 'Granska kontraktet',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator accepterade kontraktet',
                'greeting' => 'Hej :name,',
                'body' => ':creator har accepterat kontraktet för ":campaign". De kan nu börja arbeta med sitt utkast.',
                'cta' => 'Visa kampanjen',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency har publicerat ett nytt jobb',
        'greeting' => 'Hej :name,',
        'body' => ':agency har publicerat ett nytt jobb på din tavla: ":campaign". Öppna det för att se detaljerna och ansöka.',
        'cta' => 'Visa jobbet',
        'ignore' => 'Du får det här meddelandet eftersom du finns på kreatörslistan hos :agency.',
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
            'subject' => 'Ny ansökan till :campaign',
            'greeting' => 'Hej :name,',
            'body' => ':creator har sökt ”:campaign”. Öppna kampanjen för att granska ansökan och skicka ett erbjudande.',
            'cta' => 'Granska ansökan',
        ],
        'accepted' => [
            'subject' => 'Din ansökan till :campaign har accepterats',
            'greeting' => 'Hej :name,',
            'body' => ':agency har accepterat din ansökan till ”:campaign” och skickat dig ett erbjudande. Öppna uppdraget för att läsa villkoren och tacka ja eller nej.',
            'cta' => 'Visa erbjudandet',
        ],
        'rejected' => [
            'subject' => 'Nytt om din ansökan till :campaign',
            'greeting' => 'Hej :name,',
            'body_agency_rejected' => 'Tack för din ansökan till ”:campaign”. Du blev inte vald till det här jobbet. Nya jobb publiceras löpande på din tavla.',
            'body_campaign_closed' => 'Tack för din ansökan till ”:campaign”. Kampanjen är avslutad, så din ansökan går inte vidare. Nya jobb publiceras löpande på din tavla.',
            'cta' => 'Visa jobbannonser',
        ],
    ],
];
