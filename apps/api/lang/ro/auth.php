<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Parola trebuie să fie un șir de caractere.',
        'too_short' => 'Parola trebuie să aibă cel puțin :min caractere.',
        'too_long' => 'Parola nu poate depăși :max caractere.',
        'breached' => 'Această parolă a apărut în scurgeri de date cunoscute și nu poate fi utilizată. Alegeți o altă parolă.',
    ],
    'login' => [
        'invalid_credentials' => 'Email sau parolă invalide.',
        'mfa_required' => 'Autentificarea cu doi factori este necesară pentru a finaliza conectarea.',
        'account_locked_temporary' => 'Prea multe încercări de conectare eșuate. Încercați din nou în :minutes minute.',
        'account_locked' => 'Acest cont este blocat. Resetați parola sau contactați asistența.',
        'rate_limited' => 'Prea multe solicitări. Încercați din nou în :seconds secunde.',
        'wrong_spa' => 'Acest cont nu este înregistrat pentru acest site. Conectați-vă de pe site-ul corect.',
    ],
    'reset' => [
        'subject' => 'Resetați parola :app',
        'greeting' => 'Bună ziua, :name,',
        'body' => 'Am primit o solicitare de resetare a parolei contului dvs. :app. Linkul de mai jos este valabil :minutes minute.',
        'cta' => 'Resetați parola',
        'ignore' => 'Dacă nu ați solicitat acest lucru, puteți ignora în siguranță acest email — parola dvs. nu va fi modificată.',
        'invalid_token' => 'Acest link de resetare a parolei este invalid sau a expirat. Solicitați unul nou.',
        'completed' => 'Parola dvs. a fost resetată. Toate celelalte sesiuni active au fost deconectate.',
    ],
    'email_verification' => [
        'subject' => 'Verificați adresa dvs. de email :app',
        'greeting' => 'Bun venit la :app, :name!',
        'body' => 'Verificați adresa dvs. de email pentru a finaliza configurarea contului dvs. :app. Linkul de mai jos este valabil :hours ore.',
        'cta' => 'Verificați adresa de email',
        'ignore' => 'Dacă nu ați creat un cont :app, puteți ignora în siguranță acest email.',
        'verification_invalid' => 'Acest link de verificare este invalid. Solicitați unul nou.',
        'verification_expired' => 'Acest link de verificare a expirat. Solicitați unul nou.',
        'already_verified' => 'Această adresă de email a fost deja verificată.',
    ],
    'signup' => [
        'email_taken' => 'Un cont cu această adresă de email există deja.',
    ],
    'mfa' => [
        'invalid_code' => 'Codul cu doi factori este invalid. Încercați din nou.',
        'rate_limited' => 'Prea multe încercări invalide cu doi factori. Încercați din nou în :minutes minute.',
        'enrollment_suspended' => 'Autentificarea cu doi factori este suspendată pentru acest cont. Contactați asistența.',
        'enrollment_required' => 'Autentificarea cu doi factori trebuie activată înainte de a continua.',
        'already_enabled' => 'Autentificarea cu doi factori este deja activată pentru acest cont.',
        'not_enabled' => 'Autentificarea cu doi factori nu este activată pentru acest cont.',
        'provisional_expired' => 'Sesiunea de înregistrare a autentificării cu doi factori a expirat. Începeți din nou.',
    ],
];
