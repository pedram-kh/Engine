<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Geslo mora biti niz.',
        'too_short' => 'Geslo mora imeti vsaj :min znakov.',
        'too_long' => 'Geslo ne sme presegati :max znakov.',
        'breached' => 'To geslo se pojavi v znanih podatkovnih razkritjih in ga ni mogoče uporabiti. Izberite drugo geslo.',
    ],

    'login' => [
        'invalid_credentials' => 'Neveljavna e-pošta ali geslo.',
        'mfa_required' => 'Za dokončanje prijave je potrebna dvofaktorska overitev.',
        'account_locked_temporary' => 'Preveč neuspešnih poskusov prijave. Poskusite znova čez :minutes minut.',
        'account_locked' => 'Ta račun je bil zaklenjen. Ponastavite geslo ali se obrnite na podporo.',
        'rate_limited' => 'Preveč zahtev. Poskusite znova čez :seconds sekund.',
        'wrong_spa' => 'Ta račun ni registriran za to stran. Prijavite se na pravi strani.',
    ],

    'reset' => [
        'subject' => 'Ponastavite geslo za :app',
        'greeting' => 'Pozdravljeni, :name,',
        'body' => 'Prejeli smo zahtevo za ponastavitev gesla za vaš račun :app. Spodnja povezava velja :minutes minut.',
        'cta' => 'Ponastavi geslo',
        'ignore' => 'Če tega niste zahtevali, lahko to e-sporočilo varno prezrete — vaše geslo se ne bo spremenilo.',
        'invalid_token' => 'Ta povezava za ponastavitev gesla je neveljavna ali je potekla. Zahtevajte novo.',
        'completed' => 'Vaše geslo je bilo ponastavljeno. Vse druge aktivne seje so bile odjavljene.',
    ],

    'email_verification' => [
        'subject' => 'Preverite vaš e-naslov za :app',
        'greeting' => 'Dobrodošli v :app, :name!',
        'body' => 'Potrdite vaš e-naslov za dokončanje nastavitve računa :app. Spodnja povezava velja :hours ur.',
        'cta' => 'Preveri e-naslov',
        'ignore' => 'Če niste ustvarili računa :app, lahko to e-sporočilo varno prezrete.',
        'verification_invalid' => 'Ta potrditvena povezava je neveljavna. Zahtevajte novo.',
        'verification_expired' => 'Ta potrditvena povezava je potekla. Zahtevajte novo.',
        'already_verified' => 'Ta e-naslov je bil že preverjen.',
    ],

    'signup' => [
        'email_taken' => 'Račun s tem e-naslovom že obstaja.',
    ],

    'mfa' => [
        'invalid_code' => 'Dvofaktorska koda je neveljavna. Poskusite znova.',
        'rate_limited' => 'Preveč neveljavnih dvofaktorskih poskusov. Poskusite znova čez :minutes minut.',
        'enrollment_suspended' => 'Dvofaktorska overitev je bila začasno prekinjena za ta račun. Obrnite se na podporo.',
        'enrollment_required' => 'Dvofaktorska overitev mora biti omogočena pred nadaljevanjem.',
        'already_enabled' => 'Dvofaktorska overitev je že omogočena za ta račun.',
        'not_enabled' => 'Dvofaktorska overitev ni omogočena za ta račun.',
        'provisional_expired' => 'Seja registracije dvofaktorske overitve je potekla. Začnite znova.',
    ],
];
