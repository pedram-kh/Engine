<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Jūs esat uzaicināts pievienoties :agency platformā Catalyst',
            'greeting' => 'Sveiki,',
            'body' => ':agency uzaicināja jūs pievienoties viņu komandai platformā Catalyst. Noklikšķiniet zemāk esošo pogu un iestatiet savu radītāja profilu.',
            'cta' => 'Sākt',
            'expiry' => 'Šis uzaicinājums beidzas :date.',
            'ignore' => 'Ja negaidījāt šo uzaicinājumu, varat droši ignorēt šo e-pastu.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Jūsu Catalyst pieteikums ir apstiprināts',
            'greeting' => 'Sveiki, :name,',
            'body' => 'Lieliskas ziņas — jūsu radītāja pieteikums ir apstiprināts. Tagad jums ir pilna piekļuve Catalyst vadības panelim.',
            'cta' => 'Doties uz vadības paneli',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Jūsu Catalyst pieteikuma atjauninājums',
            'greeting' => 'Sveiki, :name,',
            'body' => 'Paldies, ka pieteicāties Catalyst. Pēc pārskatīšanas mēs šobrīd nevaram apstiprināt jūsu pieteikumu.',
            'reason_label' => 'Iemesls',
            'resubmit_hint' => 'Varat atjaunināt un atkārtoti iesniegt pieteikumu turpmākai pārskatīšanai no vadības paneļa.',
            'cta' => 'Pārskatīt pieteikumu',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency vēlas savienoties ar jums Catalyst platformā',
            'greeting' => 'Sveiki, :name,',
            'body' => ':agency vēlētos pievienot jūs savai komandai Catalyst platformā. Atveriet vadības paneli un pieņemiet vai noraidiet pieprasījumu.',
            'cta' => 'Skatīt pieprasījumu',
            'ignore' => 'Ja nepazīstat šo aģentūru, vienkārši noraidiet to — nekas nemainīsies, kamēr nepieņemsiet.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency ir atjauninājusi jūsu sadarbības statusu Catalyst platformā',
            'greeting' => 'Sveiki, :name,',
            'body' => ':agency ir atjauninājusi jūsu sadarbības statusu Catalyst platformā. Ja jums ir kādi jautājumi, sazinieties ar viņiem tieši.',
            'closing' => 'Paldies, ka esat daļa no Catalyst.',
        ],
    ],
];
