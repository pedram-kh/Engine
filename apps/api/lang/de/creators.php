<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Du wurdest zu :agency auf Catalyst eingeladen',
            'greeting' => 'Hallo,',
            'body' => ':agency hat dich eingeladen, ihrem Roster auf Catalyst beizutreten. Klicke auf die Schaltfläche unten, um dein Creator-Profil einzurichten.',
            'cta' => 'Loslegen',
            'expiry' => 'Diese Einladung läuft am :date ab.',
            'ignore' => 'Wenn du diese Einladung nicht erwartet hast, kannst du diese E-Mail ignorieren.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Deine Catalyst-Bewerbung wurde genehmigt',
            'greeting' => 'Hallo :name,',
            'body' => 'Gute Neuigkeiten – deine Creator-Bewerbung wurde genehmigt. Du hast jetzt vollen Zugriff auf dein Catalyst-Dashboard.',
            'cta' => 'Zum Dashboard',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Eine Aktualisierung zu deiner Catalyst-Bewerbung',
            'greeting' => 'Hallo :name,',
            'body' => 'Vielen Dank für deine Bewerbung bei Catalyst. Nach Überprüfung können wir deine Bewerbung derzeit leider nicht genehmigen.',
            'reason_label' => 'Grund',
            'resubmit_hint' => 'Du kannst deine Bewerbung aktualisieren und über dein Dashboard erneut zur Überprüfung einreichen.',
            'cta' => 'Bewerbung ansehen',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency möchte sich auf Catalyst mit dir verbinden',
            'greeting' => 'Hallo :name,',
            'body' => ':agency möchte dich zu ihrem Roster auf Catalyst hinzufügen. Öffne dein Dashboard, um die Anfrage anzunehmen oder abzulehnen.',
            'cta' => 'Anfrage ansehen',
            'ignore' => 'Wenn du diese Agentur nicht kennst, kannst du die Anfrage einfach ablehnen – es ändert sich nichts, bis du sie annimmst.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency hat deinen Kooperationsstatus auf Catalyst aktualisiert',
            'greeting' => 'Hallo :name,',
            'body' => ':agency hat deinen Kooperationsstatus auf Catalyst aktualisiert. Wenn du Fragen hast, wende dich bitte direkt an sie.',
            'closing' => 'Vielen Dank, dass du Teil von Catalyst bist.',
        ],
    ],
];
