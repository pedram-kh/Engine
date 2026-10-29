<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Le mot de passe doit être une chaîne de caractères.',
        'too_short' => 'Le mot de passe doit comporter au moins :min caractères.',
        'too_long' => 'Le mot de passe ne doit pas dépasser :max caractères.',
        'breached' => 'Ce mot de passe figure dans des fuites de données connues et ne peut pas être utilisé. Veuillez en choisir un autre.',
    ],

    'login' => [
        'invalid_credentials' => 'Adresse e-mail ou mot de passe invalide.',
        'mfa_required' => "L'authentification multifacteur est requise pour terminer la connexion.",
        'account_locked_temporary' => 'Trop de tentatives de connexion échouées. Veuillez réessayer dans :minutes minutes.',
        'account_locked' => 'Ce compte a été verrouillé. Réinitialisez votre mot de passe ou contactez le support pour récupérer l\'accès.',
        'rate_limited' => 'Trop de requêtes. Veuillez réessayer dans :seconds secondes.',
        'wrong_spa' => "Ce compte n'est pas enregistré pour ce site. Veuillez vous connecter sur le bon site.",
    ],

    'reset' => [
        'subject' => 'Réinitialisez votre mot de passe :app',
        'greeting' => 'Bonjour :name,',
        'body' => 'Nous avons reçu une demande de réinitialisation du mot de passe de votre compte :app. Le lien ci-dessous est valable pendant :minutes minutes.',
        'cta' => 'Réinitialiser le mot de passe',
        'ignore' => "Si vous n'êtes pas à l'origine de cette demande, vous pouvez ignorer cet e-mail en toute sécurité — votre mot de passe ne changera pas.",
        'invalid_token' => 'Ce lien de réinitialisation du mot de passe est invalide ou a expiré. Demandez-en un nouveau.',
        'completed' => 'Votre mot de passe a été réinitialisé. Toutes les autres sessions actives ont été déconnectées.',
    ],

    'email_verification' => [
        'subject' => 'Vérifiez votre adresse e-mail :app',
        'greeting' => 'Bienvenue sur :app, :name !',
        'body' => 'Veuillez confirmer votre adresse e-mail pour terminer la configuration de votre compte :app. Le lien ci-dessous est valable pendant :hours heures.',
        'cta' => "Vérifier l'adresse e-mail",
        'ignore' => "Si vous n'avez pas créé de compte :app, vous pouvez ignorer cet e-mail en toute sécurité.",
        'verification_invalid' => 'Ce lien de vérification est invalide. Demandez-en un nouveau.',
        'verification_expired' => 'Ce lien de vérification a expiré. Demandez-en un nouveau.',
        'already_verified' => 'Cette adresse e-mail a déjà été vérifiée.',
    ],

    'signup' => [
        'email_taken' => 'Un compte avec cette adresse e-mail existe déjà. Connectez-vous pour l\'utiliser ou réinitialisez votre mot de passe. Si vous avez demandé la suppression de ce compte, vous pouvez le restaurer en vous connectant.',
    ],

    'mfa' => [
        'invalid_code' => 'Le code à deux facteurs est invalide. Veuillez réessayer.',
        'rate_limited' => 'Trop de tentatives à deux facteurs invalides. Veuillez réessayer dans :minutes minutes.',
        'enrollment_suspended' => "L'authentification à deux facteurs a été suspendue pour ce compte. Veuillez contacter le support pour rétablir l'accès.",
        'enrollment_required' => "L'authentification à deux facteurs doit être activée avant de pouvoir continuer.",
        'already_enabled' => "L'authentification à deux facteurs est déjà activée pour ce compte.",
        'not_enabled' => "L'authentification à deux facteurs n'est pas activée pour ce compte.",
        'provisional_expired' => "La session d'activation de l'authentification à deux facteurs a expiré. Veuillez recommencer.",
    ],

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'Votre double authentification a été réinitialisée',
            'greeting' => 'Bonjour :name,',
            'body' => "Un administrateur de la plateforme a réinitialisé la double authentification de votre compte :app. Votre ancienne application d'authentification et vos codes de secours ne fonctionnent plus.",
            'next' => "Il vous sera demandé de reconfigurer la double authentification lors de votre prochain accès à une partie protégée de l'application.",
            'unexpected' => "Si vous n'êtes pas à l'origine de cette demande, contactez immédiatement le support.",
            'cta' => 'Aller sur :app',
        ],
        'exempted' => [
            'subject' => "La double authentification n'est plus obligatoire sur votre compte",
            'greeting' => 'Bonjour :name,',
            'body' => "Un administrateur de la plateforme a supprimé l'obligation de double authentification sur votre compte :app. Vous pouvez désormais utiliser sans configuration les espaces qui vous la demandaient auparavant.",
            'still_enrolled' => 'Si vous avez déjà configuré la double authentification, elle reste active et un code vous sera encore demandé à la connexion — vous pouvez la conserver ou la désactiver dans vos paramètres.',
            'unexpected' => 'Si vous ne vous attendiez pas à ce changement, contactez le support.',
        ],
        'exemption_removed' => [
            'subject' => 'La double authentification est de nouveau obligatoire sur votre compte',
            'greeting' => 'Bonjour :name,',
            'body' => "Un administrateur de la plateforme a rétabli l'obligation de double authentification sur votre compte :app.",
            'next' => "Lors de votre prochain accès à un espace protégé, il vous sera demandé de configurer la double authentification, si ce n'est pas déjà fait.",
            'unexpected' => 'Si vous ne vous attendiez pas à ce changement, contactez le support.',
        ],
    ],
    'deletion_mail' => [
        'subject' => 'Votre compte :app est programmé pour être supprimé',
        'greeting' => 'Bonjour :name,',
        'body' => 'Nous avons reçu votre demande de suppression de votre compte :app. Il sera supprimé définitivement après le :date, et cela ne pourra plus être annulé ensuite.',
        'keep' => 'D\'ici là, votre compte est verrouillé et rien n\'a été effacé. Pour le conserver, connectez-vous avec votre e-mail et votre mot de passe avant cette date et choisissez de le restaurer.',
        'unexpected' => 'Si vous n\'êtes pas à l\'origine de cette demande, quelqu\'un d\'autre connaît peut-être votre mot de passe. Restaurez le compte en vous connectant, puis changez votre mot de passe.',
        'support' => 'Des questions ? Écrivez à :support.',
    ],
];
