<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Parool peab olema string.',
        'too_short' => 'Parool peab olema vähemalt :min tähemärki.',
        'too_long' => 'Parool ei tohi olla pikem kui :max tähemärki.',
        'breached' => 'See parool on esinenud teadaolevates andmeleketes ja seda ei saa kasutada. Valige mõni muu parool.',
    ],
    'login' => [
        'invalid_credentials' => 'Vale e-posti aadress või parool.',
        'mfa_required' => 'Sisselogimise lõpuleviimiseks on vaja kahefaktorilist autentimist.',
        'account_locked_temporary' => 'Liiga palju ebaõnnestunud sisselogimiskatseid. Proovige uuesti :minutes minuti pärast.',
        'account_locked' => 'See konto on lukustatud. Lähtestage oma parool või võtke ühendust toega.',
        'rate_limited' => 'Liiga palju päringuid. Proovige uuesti :seconds sekundi pärast.',
        'wrong_spa' => 'See konto ei ole selle saidi jaoks registreeritud. Logige sisse õigel saidil.',
    ],
    'reset' => [
        'subject' => 'Lähtestage oma :app parool',
        'greeting' => 'Tere, :name,',
        'body' => 'Saime taotluse teie :app konto parooli lähtestamiseks. Allolev link kehtib :minutes minutit.',
        'cta' => 'Lähtesta parool',
        'ignore' => 'Kui te seda ei taotlenud, võite selle e-kirja turvaliselt ignoreerida — teie parooli ei muudeta.',
        'invalid_token' => 'See parooli lähtestamise link on kehtetu või aegunud. Taotlege uut.',
        'completed' => 'Teie parool on lähtestatud. Kõik muud aktiivsed seansid on välja logitud.',
    ],
    'email_verification' => [
        'subject' => 'Kinnitage oma :app e-posti aadress',
        'greeting' => 'Tere tulemast rakendusse :app, :name!',
        'body' => 'Kinnitage oma e-posti aadress, et lõpetada oma :app konto seadistamine. Allolev link kehtib :hours tundi.',
        'cta' => 'Kinnita e-posti aadress',
        'ignore' => 'Kui te ei loonud :app kontot, võite selle e-kirja turvaliselt ignoreerida.',
        'verification_invalid' => 'See kinnituslink on kehtetu. Taotlege uut.',
        'verification_expired' => 'See kinnituslink on aegunud. Taotlege uut.',
        'already_verified' => 'See e-posti aadress on juba kinnitatud.',
    ],
    'signup' => [
        'email_taken' => 'Selle e-posti aadressiga konto on juba olemas.',
    ],
    'mfa' => [
        'invalid_code' => 'Kahefaktoriline kood on kehtetu. Proovige uuesti.',
        'rate_limited' => 'Liiga palju kehtetuid kahefaktorilisi katseid. Proovige uuesti :minutes minuti pärast.',
        'enrollment_suspended' => 'Selle konto kahefaktoriline autentimine on peatatud. Võtke ühendust toega.',
        'enrollment_required' => 'Enne jätkamist tuleb kahefaktoriline autentimine lubada.',
        'already_enabled' => 'Kahefaktoriline autentimine on selle konto jaoks juba lubatud.',
        'not_enabled' => 'Kahefaktoriline autentimine ei ole selle konto jaoks lubatud.',
        'provisional_expired' => 'Kahefaktorilise autentimise registreerimise seanss on aegunud. Alustage uuesti.',
    ],
];
