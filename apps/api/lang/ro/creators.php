<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Ați fost invitat să vă alăturați :agency pe Catalyst',
            'greeting' => 'Bună ziua,',
            'body' => ':agency v-a invitat să vă alăturați echipei lor pe Catalyst. Faceți clic pe butonul de mai jos și configurați-vă profilul de creator.',
            'cta' => 'Începeți',
            'expiry' => 'Această invitație expiră la :date.',
            'ignore' => 'Dacă nu vă așteptați la această invitație, puteți ignora în siguranță acest email.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Cererea dvs. Catalyst a fost aprobată',
            'greeting' => 'Bună ziua, :name,',
            'body' => 'Vești excelente — cererea dvs. de creator a fost aprobată. Acum aveți acces complet la panoul de control Catalyst.',
            'cta' => 'Mergeți la panoul de control',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Actualizare privind cererea dvs. Catalyst',
            'greeting' => 'Bună ziua, :name,',
            'body' => 'Vă mulțumim că ați aplicat la Catalyst. După revizuire, în prezent nu putem aproba cererea dvs.',
            'reason_label' => 'Motiv',
            'resubmit_hint' => 'Puteți actualiza și retrimite cererea dvs. pentru revizuire ulterioară din panoul de control.',
            'cta' => 'Revizuiți cererea',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency dorește să se conecteze cu dvs. pe Catalyst',
            'greeting' => 'Bună ziua, :name,',
            'body' => ':agency ar dori să vă adauge în echipa lor pe Catalyst. Deschideți panoul de control și acceptați sau respingeți solicitarea.',
            'cta' => 'Vizualizați solicitarea',
            'ignore' => 'Dacă nu cunoașteți această agenție, respingeți-o — nimic nu se va schimba până când nu acceptați.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Finalizează configurarea contului tău Catalyst',
            'greeting' => 'Bună :name,',
            'body' => 'Ai început să îți creezi contul de creator pe Catalyst, dar nu ți-ai confirmat încă adresa de e-mail. Confirm-o acum pentru a relua de unde ai rămas și a-ți finaliza înregistrarea.',
            'cta' => 'Confirmă e-mailul',
            'expiry' => 'Acest link expiră în :hours ore. Dacă expiră, poți solicita unul nou din pagina de autentificare.',
            'ignore' => 'Dacă nu tu ai început această înregistrare, poți ignora acest e-mail.',
        ],
        'finish' => [
            'subject' => 'Finalizează configurarea profilului tău de creator pe Catalyst',
            'greeting' => 'Bună :name,',
            'body' => 'Ai început să îți configurezi profilul de creator pe Catalyst, dar nu l-ai finalizat încă. Reia de unde ai rămas pentru a-ți finaliza înregistrarea.',
            'cta' => 'Finalizează profilul',
            'ignore' => 'Dacă ți-ai finalizat deja profilul, poți ignora acest e-mail.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency și-a actualizat statutul de colaborare cu dvs. pe Catalyst',
            'greeting' => 'Bună ziua, :name,',
            'body' => ':agency și-a actualizat statutul de colaborare cu dvs. pe Catalyst. Dacă aveți întrebări, contactați-i direct.',
            'closing' => 'Vă mulțumim că faceți parte din Catalyst.',
        ],
    ],
];
