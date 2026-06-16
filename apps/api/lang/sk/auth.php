<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Heslo musí byť reťazec.',
        'too_short' => 'Heslo musí mať aspoň :min znakov.',
        'too_long' => 'Heslo nesmie presiahnuť :max znakov.',
        'breached' => 'Toto heslo sa vyskytuje v známych únikoch údajov a nie je možné ho použiť. Zvoľte iné heslo.',
    ],

    'login' => [
        'invalid_credentials' => 'Neplatný e-mail alebo heslo.',
        'mfa_required' => 'Na dokončenie prihlásenia je vyžadované dvojfaktorové overenie.',
        'account_locked_temporary' => 'Príliš veľa neúspešných pokusov o prihlásenie. Skúste znova o :minutes minút.',
        'account_locked' => 'Tento účet bol zablokovaný. Obnovte heslo alebo kontaktujte podporu.',
        'rate_limited' => 'Príliš veľa požiadaviek. Skúste znova o :seconds sekúnd.',
        'wrong_spa' => 'Tento účet nie je zaregistrovaný pre túto stránku. Prihláste sa na správnej stránke.',
    ],

    'reset' => [
        'subject' => 'Obnovte heslo do :app',
        'greeting' => 'Dobrý deň, :name,',
        'body' => 'Dostali sme žiadosť o obnovenie hesla k vášmu účtu :app. Odkaz nižšie platí :minutes minút.',
        'cta' => 'Obnoviť heslo',
        'ignore' => 'Ak ste o to nežiadali, môžete tento e-mail bezpečne ignorovať — vaše heslo sa nezmení.',
        'invalid_token' => 'Tento odkaz na obnovenie hesla je neplatný alebo vypršal. Vyžiadajte si nový.',
        'completed' => 'Vaše heslo bolo obnovené. Všetky ostatné aktívne relácie boli odhlásené.',
    ],

    'email_verification' => [
        'subject' => 'Overte svoju e-mailovú adresu :app',
        'greeting' => 'Vitajte v :app, :name!',
        'body' => 'Potvrďte svoju e-mailovú adresu na dokončenie nastavenia účtu :app. Odkaz nižšie platí :hours hodín.',
        'cta' => 'Overiť e-mailovú adresu',
        'ignore' => 'Ak ste si nevytvorili účet :app, môžete tento e-mail bezpečne ignorovať.',
        'verification_invalid' => 'Tento overovací odkaz je neplatný. Vyžiadajte si nový.',
        'verification_expired' => 'Tento overovací odkaz vypršal. Vyžiadajte si nový.',
        'already_verified' => 'Táto e-mailová adresa už bola overená.',
    ],

    'signup' => [
        'email_taken' => 'Účet s touto e-mailovou adresou už existuje.',
    ],

    'mfa' => [
        'invalid_code' => 'Dvojfaktorový kód je neplatný. Skúste znova.',
        'rate_limited' => 'Príliš veľa neplatných dvojfaktorových pokusov. Skúste znova o :minutes minút.',
        'enrollment_suspended' => 'Dvojfaktorové overenie bolo pozastavené pre tento účet. Kontaktujte podporu.',
        'enrollment_required' => 'Dvojfaktorové overenie musí byť povolené pred pokračovaním.',
        'already_enabled' => 'Dvojfaktorové overenie je už povolené pre tento účet.',
        'not_enabled' => 'Dvojfaktorové overenie nie je povolené pre tento účet.',
        'provisional_expired' => 'Relácia registrácie dvojfaktorového overenia vypršala. Začnite znova.',
    ],
];
