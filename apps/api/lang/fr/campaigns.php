<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Brouillon :n',
        'round_subject' => ':subject (:round)',
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator a soumis un brouillon pour révision',
                'greeting' => 'Bonjour :name,',
                'body' => ':creator a soumis un brouillon pour « :campaign ». Ouvrez la campagne pour l\'approuver, demander des modifications ou le rejeter.',
                'cta' => 'Examiner le brouillon',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Votre brouillon pour :campaign a été approuvé',
                'subject_revision_requested' => 'Modifications demandées sur votre brouillon :campaign',
                'subject_rejected' => 'Une mise à jour concernant votre brouillon :campaign',
                'greeting' => 'Bonjour :name,',
                'body_approved' => 'Bonne nouvelle — votre brouillon pour « :campaign » a été approuvé. Vous pouvez maintenant le publier et soumettre le lien en ligne.',
                'body_revision_requested' => "L'agence a demandé des modifications sur votre brouillon pour « :campaign ». Consultez les commentaires ci-dessous et soumettez-le à nouveau.",
                'body_rejected' => "Après révision, votre brouillon pour « :campaign » n'a pas été accepté et la mission a été clôturée.",
                'feedback_label' => 'Commentaires',
                'cta' => 'Voir la mission',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Impossible de vérifier la publication pour :campaign',
                'greeting' => 'Bonjour :name,',
                'body' => 'Nous n\'avons pas pu vérifier automatiquement la publication de :creator pour « :campaign ». Veuillez examiner le lien soumis.',
                'reason_label' => "Ce qui s'est passé",
                'reason_not_found' => 'La publication est introuvable au lien soumis.',
                'reason_mismatch' => 'La publication au lien soumis ne semble pas appartenir au compte connecté du créateur.',
                'cta' => 'Examiner la mission',
            ],
        ],
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Votre travail pour :campaign est terminé',
                'greeting' => 'Bonjour :name,',
                'body' => 'Votre brouillon pour « :campaign » a été approuvé. Sur cette campagne, c’est l’agence qui publie le contenu : votre mission est donc terminée, vous n’avez plus rien à faire.',
                'cta' => 'Voir la mission',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Votre publication pour :campaign a été acceptée',
                'greeting' => 'Bonjour :name,',
                'body' => "Bonne nouvelle — l'agence a examiné et accepté votre publication pour « :campaign ». Aucune autre action n'est nécessaire.",
                'cta' => 'Voir la mission',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Action requise sur votre publication :campaign',
                'greeting' => 'Bonjour :name,',
                'body_fresh' => "L'agence n'a pas pu vérifier votre publication pour « :campaign » et vous demande de soumettre un nouveau lien de publication. Ouvrez la mission pour le soumettre à nouveau.",
                'body_in_place' => "L'agence n'a pas pu vérifier votre publication pour « :campaign » et vous demande de corriger le lien soumis. Ouvrez la mission pour le mettre à jour.",
                'feedback_label' => "Note de l'agence",
                'cta' => 'Ouvrir la mission',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Contrat prêt pour :campaign',
                'greeting' => 'Bonjour :name,',
                'body' => 'Un contrat pour « :campaign » est prêt à être examiné. Ouvrez la mission pour lire les conditions et accepter.',
                'cta' => 'Examiner le contrat',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator a accepté le contrat',
                'greeting' => 'Bonjour :name,',
                'body' => ':creator a accepté le contrat pour « :campaign ». Il peut désormais commencer à travailler sur son brouillon.',
                'cta' => 'Voir la campagne',
            ],
        ],
        'invite_received' => [
            'email' => [
                'subject_fresh' => 'Vous avez une nouvelle offre pour :campaign',
                'subject_re_offer' => 'Offre mise à jour pour :campaign',
                'greeting' => 'Bonjour :name,',
                'body_fresh' => 'Vous avez été invité(e) à travailler sur « :campaign ». Ouvrez la mission pour consulter l\'offre et répondre.',
                'body_re_offer' => 'Une offre mise à jour vous attend sur « :campaign ». Ouvrez la mission pour la consulter et répondre.',
                'cta' => 'Voir l\'offre',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency a publié une nouvelle offre',
        'greeting' => 'Bonjour :name,',
        'body' => ':agency a publié une nouvelle offre sur votre tableau : « :campaign ». Ouvrez-la pour voir les détails et postuler.',
        'cta' => 'Voir l’offre',
        'ignore' => 'Vous recevez ce message parce que vous faites partie du portefeuille de créateurs de :agency.',
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
            'subject' => 'Nouvelle candidature pour :campaign',
            'greeting' => 'Bonjour :name,',
            'body' => ':creator a postulé à « :campaign ». Ouvrez la campagne pour examiner la candidature et envoyer une offre.',
            'cta' => 'Examiner la candidature',
        ],
        'accepted' => [
            'subject' => 'Votre candidature pour :campaign a été acceptée',
            'greeting' => 'Bonjour :name,',
            'body' => ':agency a accepté votre candidature pour « :campaign » et vous a envoyé une offre. Ouvrez la mission pour consulter les conditions, puis acceptez ou refusez.',
            'cta' => 'Voir l\'offre',
        ],
        'rejected' => [
            'subject' => 'Des nouvelles de votre candidature pour :campaign',
            'greeting' => 'Bonjour :name,',
            'body_agency_rejected' => 'Merci d\'avoir postulé à « :campaign ». Votre candidature n\'a pas été retenue pour cette offre. De nouvelles offres sont publiées régulièrement sur votre tableau.',
            'body_campaign_closed' => 'Merci d\'avoir postulé à « :campaign ». La campagne est clôturée, votre candidature ne sera donc pas poursuivie. De nouvelles offres sont publiées régulièrement sur votre tableau.',
            'cta' => 'Voir les offres d\'emploi',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators a encore une carte dans la colonne de publication. Sortez cette carte de la colonne avant de désactiver la publication par les créateurs.|[2,*] :count cartes se trouvent encore dans la colonne de publication (:creators). Sortez-les de la colonne avant de désactiver la publication par les créateurs.',
    ],
];
