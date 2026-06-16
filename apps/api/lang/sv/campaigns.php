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
];
