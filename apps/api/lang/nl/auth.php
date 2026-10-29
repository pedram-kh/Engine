<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Het wachtwoord moet een tekstreeks zijn.',
        'too_short' => 'Het wachtwoord moet minimaal :min tekens lang zijn.',
        'too_long' => 'Het wachtwoord mag niet langer zijn dan :max tekens.',
        'breached' => 'Dit wachtwoord is bekend uit datalekken en kan niet worden gebruikt. Kies een ander wachtwoord.',
    ],

    'login' => [
        'invalid_credentials' => 'Ongeldig e-mailadres of wachtwoord.',
        'mfa_required' => 'Multi-factor authenticatie is vereist om in te loggen.',
        'account_locked_temporary' => 'Te veel mislukte inlogpogingen. Probeer het over :minutes minuten opnieuw.',
        'account_locked' => 'Dit account is vergrendeld. Reset je wachtwoord of neem contact op met support om toegang te herwinnen.',
        'rate_limited' => 'Te veel verzoeken. Probeer het over :seconds seconden opnieuw.',
        'wrong_spa' => 'Dit account is niet geregistreerd voor deze site. Log in op de juiste site.',
    ],

    'reset' => [
        'subject' => 'Reset je :app-wachtwoord',
        'greeting' => 'Hallo :name,',
        'body' => 'We hebben een verzoek ontvangen om het wachtwoord van je :app-account te resetten. De onderstaande link is :minutes minuten geldig.',
        'cta' => 'Wachtwoord resetten',
        'ignore' => 'Als je dit verzoek niet hebt gedaan, kun je deze e-mail negeren — je wachtwoord wordt niet gewijzigd.',
        'invalid_token' => 'Deze wachtwoord-resetlink is ongeldig of verlopen. Vraag een nieuwe aan.',
        'completed' => 'Je wachtwoord is gereset. Alle andere actieve sessies zijn uitgelogd.',
    ],

    'email_verification' => [
        'subject' => 'Bevestig je :app-e-mailadres',
        'greeting' => 'Welkom bij :app, :name!',
        'body' => 'Bevestig je e-mailadres om het instellen van je :app-account te voltooien. De onderstaande link is :hours uur geldig.',
        'cta' => 'E-mailadres bevestigen',
        'ignore' => 'Als je geen :app-account hebt aangemaakt, kun je deze e-mail negeren.',
        'verification_invalid' => 'Deze bevestigingslink is ongeldig. Vraag een nieuwe aan.',
        'verification_expired' => 'Deze bevestigingslink is verlopen. Vraag een nieuwe aan.',
        'already_verified' => 'Dit e-mailadres is al bevestigd.',
    ],

    'signup' => [
        'email_taken' => 'Er bestaat al een account met dit e-mailadres. Log in om het te gebruiken of stel je wachtwoord opnieuw in. Als je hebt gevraagd om dit account te verwijderen, kun je het herstellen door in te loggen.',
    ],

    'mfa' => [
        'invalid_code' => 'De twee-factor code is ongeldig. Probeer het opnieuw.',
        'rate_limited' => 'Te veel ongeldige twee-factor pogingen. Probeer het over :minutes minuten opnieuw.',
        'enrollment_suspended' => 'Twee-factor authenticatie is voor dit account opgeschort. Neem contact op met support om de toegang te herstellen.',
        'enrollment_required' => 'Twee-factor authenticatie moet worden ingesteld voordat je verder kunt gaan.',
        'already_enabled' => 'Twee-factor authenticatie is al ingeschakeld voor dit account.',
        'not_enabled' => 'Twee-factor authenticatie is niet ingeschakeld voor dit account.',
        'provisional_expired' => 'De twee-factor instelsessie is verlopen. Begin opnieuw.',
    ],

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'Je tweefactorauthenticatie is gereset',
            'greeting' => 'Hallo :name,',
            'body' => 'Een platformbeheerder heeft de tweefactorauthenticatie van je :app-account gereset. Je vorige authenticator-app en herstelcodes werken niet meer.',
            'next' => 'Bij je volgende bezoek aan een beveiligd deel van de app wordt gevraagd tweefactorauthenticatie opnieuw in te stellen.',
            'unexpected' => 'Als je hier niet om hebt gevraagd, neem dan onmiddellijk contact op met support.',
            'cta' => 'Ga naar :app',
        ],
        'exempted' => [
            'subject' => 'Tweefactorauthenticatie is niet langer verplicht op je account',
            'greeting' => 'Hallo :name,',
            'body' => 'Een platformbeheerder heeft de verplichting tot tweefactorauthenticatie van je :app-account verwijderd. Onderdelen die er eerder om vroegen, kun je nu zonder instelling gebruiken.',
            'still_enrolled' => 'Heb je tweefactorauthenticatie al ingesteld, dan blijft die actief en wordt bij het inloggen nog om een code gevraagd — je kunt die houden of uitschakelen in je instellingen.',
            'unexpected' => 'Als je deze wijziging niet verwachtte, neem dan contact op met support.',
        ],
        'exemption_removed' => [
            'subject' => 'Tweefactorauthenticatie is weer verplicht op je account',
            'greeting' => 'Hallo :name,',
            'body' => 'Een platformbeheerder heeft de verplichting tot tweefactorauthenticatie op je :app-account hersteld.',
            'next' => 'Bij je volgende bezoek aan een beveiligd onderdeel wordt gevraagd tweefactorauthenticatie in te stellen, als dat nog niet is gebeurd.',
            'unexpected' => 'Als je deze wijziging niet verwachtte, neem dan contact op met support.',
        ],
    ],
    'deletion_mail' => [
        'subject' => 'Je :app-account staat gepland voor verwijdering',
        'greeting' => 'Hoi :name,',
        'body' => 'We hebben je verzoek ontvangen om je :app-account te verwijderen. Het wordt na :date definitief verwijderd en daarna kan dit niet meer ongedaan worden gemaakt.',
        'keep' => 'Tot die tijd is je account vergrendeld en is er niets gewist. Wil je het behouden, log dan vóór die datum in met je e-mailadres en wachtwoord en kies voor herstellen.',
        'unexpected' => 'Als jij dit niet hebt aangevraagd, kent iemand anders misschien je wachtwoord. Herstel het account door in te loggen en wijzig daarna je wachtwoord.',
        'support' => 'Vragen? Schrijf naar :support.',
    ],
];
