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
        'email_taken' => 'Er bestaat al een account met dit e-mailadres.',
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
];
