<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Lozinka mora biti niz znakova.',
        'too_short' => 'Lozinka mora imati najmanje :min znakova.',
        'too_long' => 'Lozinka ne smije biti dulja od :max znakova.',
        'breached' => 'Ova lozinka pojavljuje se u poznatim curenjima podataka i ne može se koristiti. Odaberite drugu lozinku.',
    ],

    'login' => [
        'invalid_credentials' => 'Nevaljana e-pošta ili lozinka.',
        'mfa_required' => 'Za dovršetak prijave potrebna je dvofaktorska provjera autentičnosti.',
        'account_locked_temporary' => 'Previše neuspješnih pokušaja prijave. Pokušajte ponovo za :minutes minuta.',
        'account_locked' => 'Ovaj račun je zaključan. Resetirajte lozinku ili kontaktirajte podršku.',
        'rate_limited' => 'Previše zahtjeva. Pokušajte ponovo za :seconds sekundi.',
        'wrong_spa' => 'Ovaj račun nije registriran za ovu stranicu. Prijavite se na ispravnoj stranici.',
    ],

    'reset' => [
        'subject' => 'Resetirajte lozinku za :app',
        'greeting' => 'Pozdrav, :name,',
        'body' => 'Primili smo zahtjev za resetiranje lozinke vašeg :app računa. Donja veza vrijedi :minutes minuta.',
        'cta' => 'Resetiraj lozinku',
        'ignore' => 'Ako niste tražili ovo, možete sigurno zanemariti ovu e-poštu — vaša lozinka neće se promijeniti.',
        'invalid_token' => 'Ova veza za resetiranje lozinke je nevaljana ili je istekla. Zatražite novu.',
        'completed' => 'Vaša lozinka je resetirana. Sve ostale aktivne sesije su odjavljene.',
    ],

    'email_verification' => [
        'subject' => 'Provjerite svoju :app adresu e-pošte',
        'greeting' => 'Dobrodošli u :app, :name!',
        'body' => 'Potvrdite svoju adresu e-pošte kako biste dovršili postavljanje :app računa. Donja veza vrijedi :hours sati.',
        'cta' => 'Provjeri adresu e-pošte',
        'ignore' => 'Ako niste stvorili :app račun, možete sigurno zanemariti ovu e-poštu.',
        'verification_invalid' => 'Ova verifikacijska veza je nevaljana. Zatražite novu.',
        'verification_expired' => 'Ova verifikacijska veza je istekla. Zatražite novu.',
        'already_verified' => 'Ova adresa e-pošte već je verificirana.',
    ],

    'signup' => [
        'email_taken' => 'Račun s ovom adresom e-pošte već postoji. Prijavite se kako biste ga koristili ili poništite lozinku. Ako ste zatražili brisanje ovog računa, prijavom ga možete vratiti.',
    ],

    'mfa' => [
        'invalid_code' => 'Dvofaktorski kôd je nevaljani. Pokušajte ponovo.',
        'rate_limited' => 'Previše nevaljanih dvofaktorskih pokušaja. Pokušajte ponovo za :minutes minuta.',
        'enrollment_suspended' => 'Dvofaktorska provjera autentičnosti je suspendirana za ovaj račun. Kontaktirajte podršku.',
        'enrollment_required' => 'Dvofaktorska provjera autentičnosti mora biti omogućena prije nastavka.',
        'already_enabled' => 'Dvofaktorska provjera autentičnosti već je omogućena za ovaj račun.',
        'not_enabled' => 'Dvofaktorska provjera autentičnosti nije omogućena za ovaj račun.',
        'provisional_expired' => 'Sesija registracije dvofaktorske provjere autentičnosti je istekla. Počnite iznova.',
    ],

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'Vaša dvofaktorska provjera je poništena',
            'greeting' => 'Zdravo :name,',
            'body' => 'Administrator platforme poništio je dvofaktorsku provjeru na vašem računu :app. Vaša dosadašnja aplikacija za provjeru i kodovi za oporavak više ne rade.',
            'next' => 'Pri sljedećem ulazu u zaštićeni dio aplikacije bit ćete zamoljeni da dvofaktorsku provjeru ponovno postavite.',
            'unexpected' => 'Ako to niste zatražili, odmah se obratite podršci.',
            'cta' => 'Idi na :app',
        ],
        'exempted' => [
            'subject' => 'Dvofaktorska provjera više nije obavezna na vašem računu',
            'greeting' => 'Zdravo :name,',
            'body' => 'Administrator platforme ukinuo je obvezu dvofaktorske provjere na vašem računu :app. Dijelove koji su prije tražili njezino postavljanje sada možete koristiti bez nje.',
            'still_enrolled' => 'Ako dvofaktorsku provjeru već imate postavljenu, ostaje aktivna i pri prijavi ćete i dalje biti upitani za kod — možete je zadržati ili isključiti u postavkama.',
            'unexpected' => 'Ako ovu promjenu nisu očekivali, obratite se podršci.',
        ],
        'exemption_removed' => [
            'subject' => 'Dvofaktorska provjera ponovno je obavezna na vašem računu',
            'greeting' => 'Zdravo :name,',
            'body' => 'Administrator platforme vratio je obvezu dvofaktorske provjere na vašem računu :app.',
            'next' => 'Pri sljedećem ulazu u zaštićeni dio bit ćete zamoljeni da dvofaktorsku provjeru postavite, ako je još nemate.',
            'unexpected' => 'Ako ovu promjenu nisu očekivali, obratite se podršci.',
        ],
    ],
];
