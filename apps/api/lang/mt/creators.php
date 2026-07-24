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
    'admin_connected' => [
        'email' => [
            'subject' => 'Issa inti konness ma\' :agency fuq Catalyst',
            'greeting' => 'Bonġu :name,',
            'body' => 'Amministratur ta\' Catalyst qabbadk ma\' :agency fuq il-pjattaforma, ibbażat fuq ftehim li sar barra minn Catalyst. :agency issa jista\' jara l-profil tiegħek u jibgħatlek messaġġi.',
            'unexpected' => 'Jekk din il-konnessjoni mhux mistennija, jekk jogħġbok ikkuntattja lis-sapport ta\' Catalyst.',
            'cta' => 'Mur fid-dashboard tiegħek',
        ],
    ],
    'disconnected' => [
        'email' => [
            'subject' => 'Il-konnessjoni tiegħek ma\' :counterparty fuq Catalyst intemmet',
            'greeting' => 'Bonġu :name,',
            'body' => 'Amministratur ta\' Catalyst temm ir-relazzjoni tax-xogħol tiegħek ma\' :counterparty. M\'intomx aktar konnessi fuq il-pjattaforma.',
            'unexpected' => 'Jekk dan mhux mistenni, jekk jogħġbok ikkuntattja lis-sapport ta\' Catalyst.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Temm il-konfigurazzjoni tal-kont Catalyst tiegħek',
            'greeting' => 'Bonġu :name,',
            'body' => 'Bdejt toħloq il-kont tiegħek ta\' kreatur fuq Catalyst, iżda għadek ma kkonfermajtx l-indirizz elettroniku tiegħek. Ikkonfermah issa biex tkompli minn fejn waqaft u ttemm ir-reġistrazzjoni tiegħek.',
            'cta' => 'Ikkonferma l-email tiegħi',
            'expiry' => 'Din il-link tiskadi fi :hours siegħa. Jekk tiskadi, tista\' titlob waħda ġdida mill-paġna tad-dħul.',
            'ignore' => 'Jekk ma bdejtx int din ir-reġistrazzjoni, tista\' tinjora din l-email.',
        ],
        'finish' => [
            'subject' => 'Temm il-konfigurazzjoni tal-profil ta\' kreatur tiegħek fuq Catalyst',
            'greeting' => 'Bonġu :name,',
            'body' => 'Bdejt tikkonfigura l-profil tiegħek ta\' kreatur fuq Catalyst, iżda għadek ma temmejtux. Kompli minn fejn waqaft biex ittemm ir-reġistrazzjoni tiegħek.',
            'cta' => 'Temm il-profil tiegħi',
            'ignore' => 'Jekk diġà temmejt il-profil tiegħek, tista\' tinjora din l-email.',
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
