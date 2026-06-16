<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
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
    ],
];
