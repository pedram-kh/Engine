<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Adgangskoden skal være en tekststreng.',
        'too_short' => 'Adgangskoden skal være mindst :min tegn lang.',
        'too_long' => 'Adgangskoden må ikke være længere end :max tegn.',
        'breached' => 'Denne adgangskode er kendt fra datalæk og kan ikke bruges. Vælg venligst en anden.',
    ],

    'login' => [
        'invalid_credentials' => 'Ugyldig e-mailadresse eller adgangskode.',
        'mfa_required' => 'Multi-faktor-godkendelse er påkrævet for at logge ind.',
        'account_locked_temporary' => 'For mange mislykkede loginforsøg. Prøv igen om :minutes minutter.',
        'account_locked' => 'Denne konto er låst. Nulstil din adgangskode eller kontakt support for at genvinde adgangen.',
        'rate_limited' => 'For mange anmodninger. Prøv igen om :seconds sekunder.',
        'wrong_spa' => 'Denne konto er ikke registreret på dette website. Log ind på det rigtige website.',
    ],

    'reset' => [
        'subject' => 'Nulstil din :app-adgangskode',
        'greeting' => 'Hej :name,',
        'body' => 'Vi har modtaget en anmodning om at nulstille adgangskoden til din :app-konto. Linket nedenfor er gyldigt i :minutes minutter.',
        'cta' => 'Nulstil adgangskode',
        'ignore' => 'Hvis du ikke har anmodet om dette, kan du ignorere denne e-mail — din adgangskode ændres ikke.',
        'invalid_token' => 'Dette link til nulstilling af adgangskode er ugyldigt eller udløbet. Anmod venligst om et nyt.',
        'completed' => 'Din adgangskode er blevet nulstillet. Alle andre aktive sessioner er logget ud.',
    ],

    'email_verification' => [
        'subject' => 'Bekræft din :app-e-mailadresse',
        'greeting' => 'Velkommen til :app, :name!',
        'body' => 'Bekræft din e-mailadresse for at afslutte opsætningen af din :app-konto. Linket nedenfor er gyldigt i :hours timer.',
        'cta' => 'Bekræft e-mailadresse',
        'ignore' => 'Hvis du ikke har oprettet en :app-konto, kan du ignorere denne e-mail.',
        'verification_invalid' => 'Dette bekræftelseslink er ugyldigt. Anmod om et nyt.',
        'verification_expired' => 'Dette bekræftelseslink er udløbet. Anmod om et nyt.',
        'already_verified' => 'Denne e-mailadresse er allerede bekræftet.',
    ],

    'signup' => [
        'email_taken' => 'Der findes allerede en konto med denne e-mailadresse. Log ind for at bruge den, eller nulstil din adgangskode. Hvis du har bedt om at få kontoen slettet, kan du gendanne den ved at logge ind.',
    ],

    'mfa' => [
        'invalid_code' => 'To-faktor-koden er ugyldig. Prøv igen.',
        'rate_limited' => 'For mange ugyldige to-faktor-forsøg. Prøv igen om :minutes minutter.',
        'enrollment_suspended' => 'To-faktor-godkendelse er suspenderet for denne konto. Kontakt support for at genvinde adgangen.',
        'enrollment_required' => 'To-faktor-godkendelse skal opsættes, før du kan fortsætte.',
        'already_enabled' => 'To-faktor-godkendelse er allerede aktiveret for denne konto.',
        'not_enabled' => 'To-faktor-godkendelse er ikke aktiveret for denne konto.',
        'provisional_expired' => 'To-faktor-opsætningssessionen er udløbet. Start forfra.',
    ],

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'Din tofaktorgodkendelse er blevet nulstillet',
            'greeting' => 'Hej :name,',
            'body' => 'En platformsadministrator har nulstillet tofaktorgodkendelsen på din :app-konto. Din tidligere godkendelsesapp og dine gendannelseskoder virker ikke længere.',
            'next' => 'Du bliver bedt om at opsætte tofaktorgodkendelse igen, næste gang du åbner en beskyttet del af appen.',
            'unexpected' => 'Hvis du ikke har bedt om dette, skal du kontakte support med det samme.',
            'cta' => 'Gå til :app',
        ],
        'exempted' => [
            'subject' => 'Tofaktorgodkendelse er ikke længere påkrævet på din konto',
            'greeting' => 'Hej :name,',
            'body' => 'En platformsadministrator har fjernet kravet om tofaktorgodkendelse på din :app-konto. Du kan nu bruge de områder, der tidligere bad dig om at opsætte det, uden opsætning.',
            'still_enrolled' => 'Hvis du allerede har opsat tofaktorgodkendelse, forbliver den aktiv, og du bliver stadig bedt om en kode ved login — du kan beholde den eller slå den fra i dine indstillinger.',
            'unexpected' => 'Hvis du ikke forventede denne ændring, skal du kontakte support.',
        ],
        'exemption_removed' => [
            'subject' => 'Tofaktorgodkendelse er påkrævet på din konto igen',
            'greeting' => 'Hej :name,',
            'body' => 'En platformsadministrator har gendannet kravet om tofaktorgodkendelse på din :app-konto.',
            'next' => 'Næste gang du åbner et beskyttet område, bliver du bedt om at opsætte tofaktorgodkendelse, hvis du ikke allerede har gjort det.',
            'unexpected' => 'Hvis du ikke forventede denne ændring, skal du kontakte support.',
        ],
    ],
    'deletion_mail' => [
        'subject' => 'Din :app-konto er planlagt til sletning',
        'greeting' => 'Hej :name,',
        'body' => 'Vi har modtaget din anmodning om at slette din :app-konto. Den slettes permanent den :date, og det kan ikke fortrydes bagefter.',
        'keep' => 'Indtil da er din konto låst, og intet er slettet. Hvis du vil beholde den, skal du logge ind med din e-mail og adgangskode inden den dato og vælge at gendanne den.',
        'unexpected' => 'Hvis du ikke har bedt om dette, kender en anden måske din adgangskode. Gendan kontoen ved at logge ind, og skift derefter din adgangskode.',
        'support' => 'Spørgsmål? Skriv til :support.',
    ],
];
