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
        'email_taken' => 'Konts ar šo e-pasta adresi jau pastāv. Piesakieties, lai to izmantotu, vai atiestatiet paroli. Ja esat lūdzis dzēst šo kontu, pieteikšanās ļauj to atjaunot.',
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

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'Jūsu divpakāpju autentifikācija ir atiestatīta',
            'greeting' => 'Sveiki, :name,',
            'body' => 'Platformas administrators atiestatīja divpakāpju autentifikāciju jūsu :app kontā. Jūsu iepriekšējā autentifikācijas lietotne un atkopšanas kodi vairs nedarbojas.',
            'next' => 'Nākamreiz, kad atvērsiet aizsargātu lietotnes daļu, jums lūgs divpakāpju autentifikāciju iestatīt no jauna.',
            'unexpected' => 'Ja jūs to nepieprasījāt, nekavējoties sazinieties ar atbalsta dienestu.',
            'cta' => 'Atvērt :app',
        ],
        'exempted' => [
            'subject' => 'Divpakāpju autentifikācija jūsu kontā vairs nav obligāta',
            'greeting' => 'Sveiki, :name,',
            'body' => 'Platformas administrators noņēma divpakāpju autentifikācijas prasību jūsu :app kontam. Daļas, kas iepriekš prasīja tās iestatīšanu, tagad varat lietot bez tās.',
            'still_enrolled' => 'Ja divpakāpju autentifikācija jums jau ir iestatīta, tā paliek aktīva un, pierakstoties, kods joprojām tiks prasīts — varat to saglabāt vai izslēgt iestatījumos.',
            'unexpected' => 'Ja negaidījāt šo izmaiņu, sazinieties ar atbalsta dienestu.',
        ],
        'exemption_removed' => [
            'subject' => 'Divpakāpju autentifikācija jūsu kontā ir atkal obligāta',
            'greeting' => 'Sveiki, :name,',
            'body' => 'Platformas administrators atjaunoja divpakāpju autentifikācijas prasību jūsu :app kontam.',
            'next' => 'Nākamreiz, kad atvērsiet aizsargātu daļu, jums lūgs iestatīt divpakāpju autentifikāciju, ja tā vēl nav iestatīta.',
            'unexpected' => 'Ja negaidījāt šo izmaiņu, sazinieties ar atbalsta dienestu.',
        ],
    ],
    'deletion_mail' => [
        'subject' => 'Jūsu :app konts ir ieplānots dzēšanai',
        'greeting' => 'Sveiki, :name,',
        'body' => 'Mēs saņēmām jūsu pieprasījumu dzēst :app kontu. Tas tiks neatgriezeniski dzēsts pēc :date, un pēc tam to vairs nevarēs atsaukt.',
        'keep' => 'Līdz tam jūsu konts ir bloķēts un nekas nav dzēsts. Lai to saglabātu, pirms šī datuma piesakieties ar savu e-pastu un paroli un izvēlieties to atjaunot.',
        'unexpected' => 'Ja to nepieprasījāt jūs, iespējams, kāds cits zina jūsu paroli. Atjaunojiet kontu, piesakoties, un pēc tam nomainiet paroli.',
        'support' => 'Jautājumi? Rakstiet uz :support.',
    ],
];
