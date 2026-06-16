<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Slaptažodis turi būti eilutė.',
        'too_short' => 'Slaptažodis turi būti bent :min simbolių.',
        'too_long' => 'Slaptažodis negali būti ilgesnis nei :max simbolių.',
        'breached' => 'Šis slaptažodis buvo aptiktas žinomuose duomenų nutekėjimuose ir negali būti naudojamas. Pasirinkite kitą slaptažodį.',
    ],
    'login' => [
        'invalid_credentials' => 'Netinkamas el. paštas arba slaptažodis.',
        'mfa_required' => 'Norint užbaigti prisijungimą, reikalingas dviejų veiksnių autentifikavimas.',
        'account_locked_temporary' => 'Per daug nesėkmingų prisijungimo bandymų. Bandykite dar kartą po :minutes minučių.',
        'account_locked' => 'Ši paskyra užblokuota. Iš naujo nustatykite slaptažodį arba susisiekite su palaikymo tarnyba.',
        'rate_limited' => 'Per daug užklausų. Bandykite dar kartą po :seconds sekundžių.',
        'wrong_spa' => 'Ši paskyra nėra registruota šioje svetainėje. Prisijunkite per tinkamą svetainę.',
    ],
    'reset' => [
        'subject' => 'Iš naujo nustatykite savo :app slaptažodį',
        'greeting' => 'Sveiki, :name,',
        'body' => 'Gavome prašymą iš naujo nustatyti jūsų :app paskyros slaptažodį. Toliau pateikta nuoroda galioja :minutes minučių.',
        'cta' => 'Iš naujo nustatyti slaptažodį',
        'ignore' => 'Jei to neprašėte, galite saugiai ignoruoti šį el. laišką — jūsų slaptažodis nebus pakeistas.',
        'invalid_token' => 'Ši slaptažodžio nustatymo nuoroda yra netinkama arba pasibaigė jos galiojimas. Paprašykite naujos.',
        'completed' => 'Jūsų slaptažodis buvo iš naujo nustatytas. Visos kitos aktyvios sesijos buvo atjungtos.',
    ],
    'email_verification' => [
        'subject' => 'Patvirtinkite savo :app el. pašto adresą',
        'greeting' => 'Sveiki atvykę į :app, :name!',
        'body' => 'Patvirtinkite savo el. pašto adresą, kad užbaigtumėte :app paskyros sąranką. Toliau pateikta nuoroda galioja :hours valandų.',
        'cta' => 'Patvirtinti el. pašto adresą',
        'ignore' => 'Jei nesukūrėte :app paskyros, galite saugiai ignoruoti šį el. laišką.',
        'verification_invalid' => 'Ši patvirtinimo nuoroda yra netinkama. Paprašykite naujos.',
        'verification_expired' => 'Šios patvirtinimo nuorodos galiojimas pasibaigė. Paprašykite naujos.',
        'already_verified' => 'Šis el. pašto adresas jau patvirtintas.',
    ],
    'signup' => [
        'email_taken' => 'Paskyra su šiuo el. pašto adresu jau egzistuoja.',
    ],
    'mfa' => [
        'invalid_code' => 'Dviejų veiksnių kodas yra netinkamas. Bandykite dar kartą.',
        'rate_limited' => 'Per daug netinkamų dviejų veiksnių bandymų. Bandykite dar kartą po :minutes minučių.',
        'enrollment_suspended' => 'Dviejų veiksnių autentifikavimas šiai paskyrai sustabdytas. Susisiekite su palaikymo tarnyba.',
        'enrollment_required' => 'Prieš tęsiant reikia įjungti dviejų veiksnių autentifikavimą.',
        'already_enabled' => 'Dviejų veiksnių autentifikavimas šiai paskyrai jau įjungtas.',
        'not_enabled' => 'Dviejų veiksnių autentifikavimas šiai paskyrai neįjungtas.',
        'provisional_expired' => 'Dviejų veiksnių autentifikavimo registracijos sesija baigėsi. Pradėkite iš naujo.',
    ],
];
