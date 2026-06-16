<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
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
    ],
];
