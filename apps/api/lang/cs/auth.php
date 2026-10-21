<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Heslo musí být řetězec.',
        'too_short' => 'Heslo musí mít alespoň :min znaků.',
        'too_long' => 'Heslo nesmí překročit :max znaků.',
        'breached' => 'Toto heslo se vyskytuje ve známých únicích dat a nelze ho použít. Zvolte jiné heslo.',
    ],

    'login' => [
        'invalid_credentials' => 'Neplatný e-mail nebo heslo.',
        'mfa_required' => 'K dokončení přihlášení je vyžadováno dvoufaktorové ověření.',
        'account_locked_temporary' => 'Příliš mnoho neúspěšných pokusů o přihlášení. Zkuste to znovu za :minutes minut.',
        'account_locked' => 'Tento účet byl zablokován. Resetujte heslo nebo kontaktujte podporu pro obnovení přístupu.',
        'rate_limited' => 'Příliš mnoho požadavků. Zkuste to znovu za :seconds sekund.',
        'wrong_spa' => 'Tento účet není zaregistrován pro tuto stránku. Přihlaste se na správné stránce.',
    ],

    'reset' => [
        'subject' => 'Resetujte heslo k :app',
        'greeting' => 'Dobrý den, :name,',
        'body' => 'Obdrželi jsme žádost o resetování hesla k vašemu účtu :app. Odkaz níže platí :minutes minut.',
        'cta' => 'Resetovat heslo',
        'ignore' => 'Pokud jste o to nežádali, můžete tento e-mail bezpečně ignorovat — vaše heslo se nezmění.',
        'invalid_token' => 'Tento odkaz pro resetování hesla je neplatný nebo vypršel. Vyžádejte si nový.',
        'completed' => 'Vaše heslo bylo resetováno. Všechny ostatní aktivní relace byly odhlášeny.',
    ],

    'email_verification' => [
        'subject' => 'Ověřte svou e-mailovou adresu :app',
        'greeting' => 'Vítejte v :app, :name!',
        'body' => 'Potvrďte svou e-mailovou adresu pro dokončení nastavení účtu :app. Odkaz níže platí :hours hodin.',
        'cta' => 'Ověřit e-mailovou adresu',
        'ignore' => 'Pokud jste si nevytvořili účet :app, můžete tento e-mail bezpečně ignorovat.',
        'verification_invalid' => 'Tento ověřovací odkaz je neplatný. Vyžádejte si nový.',
        'verification_expired' => 'Tento ověřovací odkaz vypršel. Vyžádejte si nový.',
        'already_verified' => 'Tato e-mailová adresa již byla ověřena.',
    ],

    'signup' => [
        'email_taken' => 'Účet s touto e-mailovou adresou již existuje. Přihlaste se a použijte ho, nebo si obnovte heslo. Pokud jste požádali o smazání tohoto účtu, přihlášením jej můžete obnovit.',
    ],

    'mfa' => [
        'invalid_code' => 'Dvoufaktorový kód je neplatný. Zkuste to znovu.',
        'rate_limited' => 'Příliš mnoho neplatných dvoufaktorových pokusů. Zkuste to znovu za :minutes minut.',
        'enrollment_suspended' => 'Dvoufaktorové ověření bylo pozastaveno pro tento účet. Kontaktujte podporu pro obnovení přístupu.',
        'enrollment_required' => 'Dvoufaktorové ověření musí být povoleno před pokračováním.',
        'already_enabled' => 'Dvoufaktorové ověření je již povoleno pro tento účet.',
        'not_enabled' => 'Dvoufaktorové ověření není povoleno pro tento účet.',
        'provisional_expired' => 'Relace registrace dvoufaktorového ověření vypršela. Začněte znovu.',
    ],

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'Vaše dvoufázové ověření bylo resetováno',
            'greeting' => 'Dobrý den :name,',
            'body' => 'Administrátor platformy resetoval dvoufázové ověření u vašeho účtu :app. Vaše dosavadní autentizační aplikace a záložní kódy již nefungují.',
            'next' => 'Při dalším vstupu do chráněné části aplikace budete požádáni o nové nastavení dvoufázového ověření.',
            'unexpected' => 'Pokud jste o to nežádali, obraťte se okamžitě na podporu.',
            'cta' => 'Přejít na :app',
        ],
        'exempted' => [
            'subject' => 'Dvoufázové ověření již není u vašeho účtu vyžadováno',
            'greeting' => 'Dobrý den :name,',
            'body' => 'Administrátor platformy odstranil povinnost dvoufázového ověření u vašeho účtu :app. Části, které vás dříve vyzývaly k jeho nastavení, nyní můžete používat bez něj.',
            'still_enrolled' => 'Pokud již dvoufázové ověření nastavené máte, zůstává aktivní a při přihlášení budete stále vyzváni k zadání kódu — můžete si je ponechat, nebo je vypnout v nastavení.',
            'unexpected' => 'Pokud jste tuto změnu neočekávali, obraťte se na podporu.',
        ],
        'exemption_removed' => [
            'subject' => 'Dvoufázové ověření je u vašeho účtu opět vyžadováno',
            'greeting' => 'Dobrý den :name,',
            'body' => 'Administrátor platformy obnovil povinnost dvoufázového ověření u vašeho účtu :app.',
            'next' => 'Při dalším vstupu do chráněné části budete požádáni o nastavení dvoufázového ověření, pokud je ještě nemáte.',
            'unexpected' => 'Pokud jste tuto změnu neočekávali, obraťte se na podporu.',
        ],
    ],
];
