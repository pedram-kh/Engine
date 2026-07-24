<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Vous avez été invité à rejoindre :agency sur Catalyst',
            'greeting' => 'Bonjour,',
            'body' => ':agency vous a invité à rejoindre son roster sur Catalyst. Cliquez sur le bouton ci-dessous pour configurer votre profil de créateur.',
            'cta' => 'Commencer',
            'expiry' => 'Cette invitation expire le :date.',
            'ignore' => 'Si vous ne vous attendiez pas à cette invitation, vous pouvez ignorer cet e-mail en toute sécurité.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Votre candidature Catalyst a été approuvée',
            'greeting' => 'Bonjour :name,',
            'body' => 'Bonne nouvelle — votre candidature de créateur a été approuvée. Vous avez désormais un accès complet à votre tableau de bord Catalyst.',
            'cta' => 'Accéder à votre tableau de bord',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Une mise à jour concernant votre candidature Catalyst',
            'greeting' => 'Bonjour :name,',
            'body' => "Merci d'avoir postulé à Catalyst. Après examen, nous ne sommes pas en mesure d'approuver votre candidature pour le moment.",
            'reason_label' => 'Motif',
            'resubmit_hint' => 'Vous pouvez mettre à jour votre candidature et la soumettre à nouveau pour un autre examen depuis votre tableau de bord.',
            'cta' => 'Examiner votre candidature',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency souhaite se connecter avec vous sur Catalyst',
            'greeting' => 'Bonjour :name,',
            'body' => ':agency souhaite vous ajouter à son roster sur Catalyst. Ouvrez votre tableau de bord pour accepter ou refuser la demande.',
            'cta' => 'Voir la demande',
            'ignore' => "Si vous ne reconnaissez pas cette agence, vous pouvez simplement refuser — rien ne change tant que vous n'acceptez pas.",
        ],
    ],
    'admin_connected' => [
        'email' => [
            'subject' => 'Vous êtes maintenant connecté avec :agency sur Catalyst',
            'greeting' => 'Bonjour :name,',
            'body' => 'Un administrateur Catalyst vous a connecté avec :agency sur la plateforme, sur la base d\'un accord conclu en dehors de Catalyst. :agency peut désormais voir votre profil et vous envoyer des messages.',
            'unexpected' => 'Si cette connexion est inattendue, veuillez contacter le support Catalyst.',
            'cta' => 'Accéder à votre tableau de bord',
        ],
    ],
    'disconnected' => [
        'email' => [
            'subject' => 'Votre connexion avec :counterparty sur Catalyst a pris fin',
            'greeting' => 'Bonjour :name,',
            'body' => 'Un administrateur Catalyst a mis fin à votre relation de travail avec :counterparty. Vous n\'êtes plus connectés sur la plateforme.',
            'unexpected' => 'Si cela est inattendu, veuillez contacter le support Catalyst.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Terminez la configuration de votre compte Catalyst',
            'greeting' => 'Bonjour :name,',
            'body' => 'Vous avez commencé à créer votre compte créateur Catalyst, mais vous n\'avez pas encore confirmé votre adresse e-mail. Confirmez-la maintenant pour reprendre là où vous vous êtes arrêté et terminer votre inscription.',
            'cta' => 'Confirmer mon e-mail',
            'expiry' => 'Ce lien expire dans :hours heures. S\'il expire, vous pouvez en demander un nouveau depuis la page de connexion.',
            'ignore' => 'Si vous n\'êtes pas à l\'origine de cette inscription, vous pouvez ignorer cet e-mail.',
        ],
        'finish' => [
            'subject' => 'Terminez la configuration de votre profil créateur Catalyst',
            'greeting' => 'Bonjour :name,',
            'body' => 'Vous avez commencé à configurer votre profil créateur Catalyst, mais vous ne l\'avez pas encore terminé. Reprenez là où vous vous êtes arrêté pour terminer votre inscription.',
            'cta' => 'Terminer mon profil',
            'ignore' => 'Si vous avez déjà terminé votre profil, vous pouvez ignorer cet e-mail.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency a mis à jour votre statut de collaboration sur Catalyst',
            'greeting' => 'Bonjour :name,',
            'body' => ':agency a mis à jour votre statut de collaboration sur Catalyst. Si vous avez des questions, veuillez les contacter directement.',
            'closing' => 'Merci de faire partie de Catalyst.',
        ],
    ],
];
