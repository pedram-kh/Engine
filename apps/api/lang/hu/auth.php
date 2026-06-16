<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'A jelszónak karakterláncnak kell lennie.',
        'too_short' => 'A jelszónak legalább :min karakterből kell állnia.',
        'too_long' => 'A jelszó nem lehet hosszabb :max karakternél.',
        'breached' => 'Ez a jelszó szerepel az ismert adatszivárgásokban, és nem használható. Válasszon másik jelszót.',
    ],
    'login' => [
        'invalid_credentials' => 'Érvénytelen e-mail vagy jelszó.',
        'mfa_required' => 'A bejelentkezés befejezéséhez kétfaktoros hitelesítés szükséges.',
        'account_locked_temporary' => 'Túl sok sikertelen bejelentkezési kísérlet. Próbálja újra :minutes perc múlva.',
        'account_locked' => 'Ez a fiók zárolva van. Állítsa vissza a jelszavát, vagy lépjen kapcsolatba az ügyfélszolgálattal.',
        'rate_limited' => 'Túl sok kérés. Próbálja újra :seconds másodperc múlva.',
        'wrong_spa' => 'Ez a fiók nincs regisztrálva erre az oldalra. Jelentkezzen be a megfelelő oldalon.',
    ],
    'reset' => [
        'subject' => 'Állítsa vissza :app jelszavát',
        'greeting' => 'Kedves :name,',
        'body' => 'Kérést kaptunk :app fiókja jelszavának visszaállítására. Az alábbi link :minutes percig érvényes.',
        'cta' => 'Jelszó visszaállítása',
        'ignore' => 'Ha nem Ön kérte ezt, biztonságosan figyelmen kívül hagyhatja ezt az e-mailt — jelszava nem fog megváltozni.',
        'invalid_token' => 'Ez a jelszó-visszaállítási link érvénytelen vagy lejárt. Kérjen újat.',
        'completed' => 'Jelszava visszaállítva. Az összes többi aktív munkamenet ki lett jelentkeztetve.',
    ],
    'email_verification' => [
        'subject' => 'Erősítse meg :app e-mail címét',
        'greeting' => 'Üdvözöljük a(z) :app-ban, :name!',
        'body' => 'Erősítse meg e-mail címét :app fiókja beállításának befejezéséhez. Az alábbi link :hours óráig érvényes.',
        'cta' => 'E-mail cím megerősítése',
        'ignore' => 'Ha nem hozott létre :app fiókot, biztonságosan figyelmen kívül hagyhatja ezt az e-mailt.',
        'verification_invalid' => 'Ez az ellenőrzési link érvénytelen. Kérjen újat.',
        'verification_expired' => 'Ez az ellenőrzési link lejárt. Kérjen újat.',
        'already_verified' => 'Ez az e-mail cím már meg van erősítve.',
    ],
    'signup' => [
        'email_taken' => 'Már létezik fiók ezzel az e-mail címmel.',
    ],
    'mfa' => [
        'invalid_code' => 'A kétfaktoros kód érvénytelen. Próbálja újra.',
        'rate_limited' => 'Túl sok érvénytelen kétfaktoros kísérlet. Próbálja újra :minutes perc múlva.',
        'enrollment_suspended' => 'A kétfaktoros hitelesítés fel van függesztve ennél a fióknál. Lépjen kapcsolatba az ügyfélszolgálattal.',
        'enrollment_required' => 'A kétfaktoros hitelesítést engedélyezni kell a folytatás előtt.',
        'already_enabled' => 'A kétfaktoros hitelesítés már engedélyezve van ennél a fióknál.',
        'not_enabled' => 'A kétfaktoros hitelesítés nincs engedélyezve ennél a fióknál.',
        'provisional_expired' => 'A kétfaktoros hitelesítési regisztrációs munkamenet lejárt. Kezdje újra.',
    ],
];
