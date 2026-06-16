<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Ġejt mistieden/a tingħaqad ma\' :agency fuq Catalyst',
            'greeting' => 'Bonġu,',
            'body' => ':agency stiednek/et tingħaqad mat-tim tagħhom fuq Catalyst. Ikklikkja l-buttuna hawn taħt u ssettja l-profil tiegħek tal-kreatur.',
            'cta' => 'Ibda',
            'expiry' => 'Din l-istedina tiskadi :date.',
            'ignore' => 'Jekk ma kuntx qed tistenna din l-istedina, tista\' tinjora din l-email b\'mod sigur.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'It-talba tiegħek fuq Catalyst ġiet approvata',
            'greeting' => 'Bonġu, :name,',
            'body' => 'Aħbarijiet sbieħ — it-talba tiegħek tal-kreatur ġiet approvata. Issa għandek aċċess sħiħ għad-dashboard ta\' Catalyst.',
            'cta' => 'Mur lejn id-dashboard',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Aġġornament dwar it-talba tiegħek fuq Catalyst',
            'greeting' => 'Bonġu, :name,',
            'body' => 'Grazzi talli applikajt għal Catalyst. Wara reviżjoni, bħalissa ma nistgħux napprova t-talba tiegħek.',
            'reason_label' => 'Raġuni',
            'resubmit_hint' => 'Tista\' taġġorna u terġa\' tibgħat it-talba tiegħek għal reviżjoni ulterjuri mid-dashboard.',
            'cta' => 'Rrevedi t-talba',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency trid tikkonnettja miegħek fuq Catalyst',
            'greeting' => 'Bonġu, :name,',
            'body' => ':agency tixtieq żżidek mat-tim tagħhom fuq Catalyst. Iftaħ id-dashboard u aċċetta jew irrifjuta t-talba.',
            'cta' => 'Ara t-talba',
            'ignore' => 'Jekk ma tafsx din l-aġenzija, irrifjutaha biss — xejn ma jinbidel sakemm ma taċċettax.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency aġġornat l-istatus tal-kollaborazzjoni tiegħek fuq Catalyst',
            'greeting' => 'Bonġu, :name,',
            'body' => ':agency aġġornat l-istatus tal-kollaborazzjoni tiegħek fuq Catalyst. Jekk għandek xi mistoqsijiet, ikkuntattjahom direttament.',
            'closing' => 'Grazzi talli int parti minn Catalyst.',
        ],
    ],
];
