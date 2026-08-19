<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Concept :n',
        'round_subject' => ':subject (:round)',
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator heeft een concept ingediend ter beoordeling',
                'greeting' => 'Hallo :name,',
                'body' => ':creator heeft een concept ingediend voor ":campaign". Open de campagne om het goed te keuren, wijzigingen aan te vragen of te weigeren.',
                'cta' => 'Concept beoordelen',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Je concept voor :campaign is goedgekeurd',
                'subject_revision_requested' => 'Wijzigingen gevraagd voor je :campaign-concept',
                'subject_rejected' => 'Een update over je :campaign-concept',
                'greeting' => 'Hallo :name,',
                'body_approved' => 'Goed nieuws — je concept voor ":campaign" is goedgekeurd. Je kunt het nu publiceren en de live link indienen.',
                'body_revision_requested' => 'Het bureau heeft wijzigingen gevraagd voor je concept van ":campaign". Bekijk de feedback hieronder en dien het opnieuw in.',
                'body_rejected' => 'Na beoordeling is je concept voor ":campaign" niet geaccepteerd en de opdracht is gesloten.',
                'feedback_label' => 'Feedback',
                'cta' => 'Opdracht bekijken',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Post voor :campaign kon niet worden geverifieerd',
                'greeting' => 'Hallo :name,',
                'body' => 'We konden de post van :creator voor ":campaign" niet automatisch verifiëren. Controleer de ingediende link.',
                'reason_label' => 'Wat er is gebeurd',
                'reason_not_found' => 'De post kon niet worden gevonden via de ingediende link.',
                'reason_mismatch' => 'De post via de ingediende link lijkt niet te horen bij het gekoppelde account van de creator.',
                'cta' => 'Opdracht controleren',
            ],
        ],
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Je werk voor :campaign is afgerond',
                'greeting' => 'Hallo :name,',
                'body' => 'Je concept voor ":campaign" is goedgekeurd. Bij deze campagne plaatst het bureau de content, dus je opdracht is nu afgerond — je hoeft niets meer te doen.',
                'cta' => 'Opdracht bekijken',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Je post voor :campaign is geaccepteerd',
                'greeting' => 'Hallo :name,',
                'body' => 'Goed nieuws — het bureau heeft je post voor ":campaign" beoordeeld en geaccepteerd. Er zijn geen verdere stappen nodig.',
                'cta' => 'Opdracht bekijken',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Actie vereist voor je :campaign-post',
                'greeting' => 'Hallo :name,',
                'body_fresh' => 'Het bureau kon je post voor ":campaign" niet verifiëren en vraagt je een nieuwe postlink in te dienen. Open de opdracht om opnieuw in te dienen.',
                'body_in_place' => 'Het bureau kon je post voor ":campaign" niet verifiëren en vraagt je de ingediende link te corrigeren. Open de opdracht om deze bij te werken.',
                'feedback_label' => 'Opmerking van het bureau',
                'cta' => 'Opdracht openen',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Contract voor :campaign gereed',
                'greeting' => 'Hallo :name,',
                'body' => 'Er staat een contract klaar voor ":campaign" ter beoordeling. Open de opdracht om de voorwaarden te lezen en te accepteren.',
                'cta' => 'Contract beoordelen',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator heeft het contract geaccepteerd',
                'greeting' => 'Hallo :name,',
                'body' => ':creator heeft het contract voor ":campaign" geaccepteerd. Hij/zij kan nu beginnen met het maken van een concept.',
                'cta' => 'Campagne bekijken',
            ],
        ],
        'invite_received' => [
            'email' => [
                'subject_fresh' => 'Je hebt een nieuw aanbod voor :campaign',
                'subject_re_offer' => 'Bijgewerkt aanbod voor :campaign',
                'greeting' => 'Hallo :name,',
                'body_fresh' => 'Je bent uitgenodigd om aan ":campaign" te werken. Open de opdracht om het aanbod te bekijken en te reageren.',
                'body_re_offer' => 'Er wacht een bijgewerkt aanbod voor je op ":campaign". Open de opdracht om het te bekijken en te reageren.',
                'cta' => 'Bekijk het aanbod',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency heeft een nieuwe opdracht geplaatst',
        'greeting' => 'Hallo :name,',
        'body' => ':agency heeft een nieuwe opdracht op je bord geplaatst: ":campaign". Open de opdracht om de details te bekijken en te solliciteren.',
        'cta' => 'Bekijk de opdracht',
        'ignore' => 'Je ontvangt dit bericht omdat je op de creatorlijst van :agency staat.',
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
            'subject' => 'Nieuwe aanmelding voor :campaign',
            'greeting' => 'Hallo :name,',
            'body' => ':creator heeft gereageerd op ":campaign". Open de campagne om de aanmelding te bekijken en een aanbod te sturen.',
            'cta' => 'Aanmelding bekijken',
        ],
        'accepted' => [
            'subject' => 'Je aanmelding voor :campaign is geaccepteerd',
            'greeting' => 'Hallo :name,',
            'body' => ':agency heeft je aanmelding voor ":campaign" geaccepteerd en je een aanbod gestuurd. Open de opdracht om de voorwaarden te bekijken en het aanbod te accepteren of af te wijzen.',
            'cta' => 'Aanbod bekijken',
        ],
        'rejected' => [
            'subject' => 'Nieuws over je aanmelding voor :campaign',
            'greeting' => 'Hallo :name,',
            'body_agency_rejected' => 'Bedankt voor je aanmelding voor ":campaign". Je bent niet geselecteerd voor deze opdracht. Er komen regelmatig nieuwe vacatures op je bord.',
            'body_campaign_closed' => 'Bedankt voor je aanmelding voor ":campaign". De campagne is gesloten, dus je aanmelding gaat niet verder. Er komen regelmatig nieuwe vacatures op je bord.',
            'cta' => 'Vacatures bekijken',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators heeft nog een kaart in de plaatsingskolom. Verplaats die kaart uit de kolom voordat u plaatsen door creators uitschakelt.|[2,*] :count kaarten staan nog in de plaatsingskolom (:creators). Verplaats ze uit de kolom voordat u plaatsen door creators uitschakelt.',
    ],
];
