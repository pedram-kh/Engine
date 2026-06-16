<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Parolei jābūt virknei.',
        'too_short' => 'Parolei jābūt vismaz :min rakstzīmes.',
        'too_long' => 'Parole nedrīkst būt garāka par :max rakstzīmēm.',
        'breached' => 'Šī parole parādās zināmos datu noplūdes gadījumos un to nevar izmantot. Izvēlieties citu paroli.',
    ],
    'login' => [
        'invalid_credentials' => 'Nederīgs e-pasts vai parole.',
        'mfa_required' => 'Lai pabeigtu pierakstīšanos, nepieciešama divfaktoru autentifikācija.',
        'account_locked_temporary' => 'Pārāk daudz neveiksmīgu pierakstīšanās mēģinājumu. Mēģiniet vēlreiz pēc :minutes minūtēm.',
        'account_locked' => 'Šis konts ir bloķēts. Atiestatiet paroli vai sazinieties ar atbalstu.',
        'rate_limited' => 'Pārāk daudz pieprasījumu. Mēģiniet vēlreiz pēc :seconds sekundēm.',
        'wrong_spa' => 'Šis konts nav reģistrēts šajā vietnē. Pierakstieties pareizajā vietnē.',
    ],
    'reset' => [
        'subject' => 'Atiestatiet savu :app paroli',
        'greeting' => 'Sveiki, :name,',
        'body' => 'Saņēmām pieprasījumu atiestatīt jūsu :app konta paroli. Zemāk esošā saite ir derīga :minutes minūtes.',
        'cta' => 'Atiestatīt paroli',
        'ignore' => 'Ja jūs to neprasījāt, varat droši ignorēt šo e-pastu — jūsu parole netiks mainīta.',
        'invalid_token' => 'Šī paroles atiestatīšanas saite ir nederīga vai ir beigusies tās derīguma termiņš. Pieprasiet jaunu.',
        'completed' => 'Jūsu parole ir atiestatīta. Visas pārējās aktīvās sesijas ir atteikušās.',
    ],
    'email_verification' => [
        'subject' => 'Apstipriniet savu :app e-pasta adresi',
        'greeting' => 'Laipni lūgti :app, :name!',
        'body' => 'Apstipriniet savu e-pasta adresi, lai pabeigtu :app konta iestatīšanu. Zemāk esošā saite ir derīga :hours stundas.',
        'cta' => 'Apstiprināt e-pasta adresi',
        'ignore' => 'Ja neesat izveidojis :app kontu, varat droši ignorēt šo e-pastu.',
        'verification_invalid' => 'Šī verifikācijas saite ir nederīga. Pieprasiet jaunu.',
        'verification_expired' => 'Šīs verifikācijas saites derīguma termiņš ir beidzies. Pieprasiet jaunu.',
        'already_verified' => 'Šī e-pasta adrese jau ir apstiprināta.',
    ],
    'signup' => [
        'email_taken' => 'Konts ar šo e-pasta adresi jau pastāv.',
    ],
    'mfa' => [
        'invalid_code' => 'Divfaktoru kods ir nederīgs. Mēģiniet vēlreiz.',
        'rate_limited' => 'Pārāk daudz nederīgu divfaktoru mēģinājumu. Mēģiniet vēlreiz pēc :minutes minūtēm.',
        'enrollment_suspended' => 'Divfaktoru autentifikācija šim kontam ir apturēta. Sazinieties ar atbalstu.',
        'enrollment_required' => 'Divfaktoru autentifikācijai jābūt iespējotai pirms turpināšanas.',
        'already_enabled' => 'Divfaktoru autentifikācija jau ir iespējota šim kontam.',
        'not_enabled' => 'Divfaktoru autentifikācija nav iespējota šim kontam.',
        'provisional_expired' => 'Divfaktoru autentifikācijas reģistrācijas sesija ir beigusies. Sāciet no jauna.',
    ],
];
