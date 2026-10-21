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
        'email_taken' => 'Účet s touto e-mailovou adresou už existuje. Prihláste sa a používajte ho, alebo si obnovte heslo. Ak ste požiadali o zmazanie tohto účtu, prihlásením ho môžete obnoviť.',
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

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'Vaše dvojfaktorové overenie bolo resetované',
            'greeting' => 'Dobrý deň :name,',
            'body' => 'Administrátor platformy resetoval dvojfaktorové overenie na vašom konte :app. Vaša doterajšia autentifikačná aplikácia a záložné kódy už nefungujú.',
            'next' => 'Pri ďalšom vstupe do chránenej časti aplikácie budete požiadaní o nové nastavenie dvojfaktorového overenia.',
            'unexpected' => 'Ak ste o to nežiadali, okamžite kontaktujte podporu.',
            'cta' => 'Prejsť na :app',
        ],
        'exempted' => [
            'subject' => 'Dvojfaktorové overenie už na vašom konte nie je povinné',
            'greeting' => 'Dobrý deň :name,',
            'body' => 'Administrátor platformy odstránil povinnosť dvojfaktorového overenia z vášho konta :app. Časti, ktoré vás predtým vyzývali na jeho nastavenie, teraz môžete používať bez neho.',
            'still_enrolled' => 'Ak už dvojfaktorové overenie nastavené máte, zostáva aktívne a pri prihlásení budete stále vyzvaní na zadanie kódu — môžete si ho ponechať alebo ho vypnúť v nastaveniach.',
            'unexpected' => 'Ak ste túto zmenu neočakávali, kontaktujte podporu.',
        ],
        'exemption_removed' => [
            'subject' => 'Dvojfaktorové overenie je na vašom konte opäť povinné',
            'greeting' => 'Dobrý deň :name,',
            'body' => 'Administrátor platformy obnovil povinnosť dvojfaktorového overenia na vašom konte :app.',
            'next' => 'Pri ďalšom vstupe do chránenej časti budete požiadaní o nastavenie dvojfaktorového overenia, ak ho ešte nemáte.',
            'unexpected' => 'Ak ste túto zmenu neočakávali, kontaktujte podporu.',
        ],
    ],
];
