<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Tugadh cuireadh duit :agency a iontráil ar Catalyst',
            'greeting' => 'Dia duit,',
            'body' => 'Thug :agency cuireadh duit a gceann foirne a iontráil ar Catalyst. Cliceáil an cnaipe thíos agus socraigh do phróifíl chruthaitheora.',
            'cta' => 'Tosaigh',
            'expiry' => 'Rachaidh an cuireadh seo in éag ar :date.',
            'ignore' => 'Mura raibh tú ag súil leis an gcuireadh seo, is féidir leat an ríomhphost seo a neamhaird a dhéanamh go sábháilte.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Ceadaíodh d\'iarratas Catalyst',
            'greeting' => 'Dia duit, :name,',
            'body' => 'Nuacht iontach — ceadaíodh d\'iarratas cruthaitheora. Tá rochtain iomlán agat anois ar dheais Catalyst.',
            'cta' => 'Téigh go deais',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Nuashonrú ar d\'iarratas Catalyst',
            'greeting' => 'Dia duit, :name,',
            'body' => 'Go raibh maith agat as iarratas a dhéanamh ar Catalyst. Tar éis athbhreithnithe, ní féidir linn d\'iarratas a cheadú faoi láthair.',
            'reason_label' => 'Cúis',
            'resubmit_hint' => 'Is féidir leat d\'iarratas a nuashonrú agus a athchur isteach le haghaidh athbhreithnithe breise ón deais.',
            'cta' => 'Athbhreithnigh iarratas',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => 'Tá :agency ag iarraidh ceangal leat ar Catalyst',
            'greeting' => 'Dia duit, :name,',
            'body' => 'Ba mhaith le :agency tú a chur lena gceann foirne ar Catalyst. Oscail an deais agus glac nó diúltaigh don iarraidh.',
            'cta' => 'Féach ar iarraidh',
            'ignore' => 'Mura n-aithníonn tú an ghníomhaireacht seo, diúltaigh di — ní athróidh aon rud go dtí go nglacfaidh tú leis.',
        ],
    ],
    'admin_connected' => [
        'email' => [
            'subject' => 'Tá tú ceangailte le :agency ar Catalyst anois',
            'greeting' => 'Dia duit :name,',
            'body' => 'Cheangail riarthóir Catalyst thú le :agency ar an ardán, bunaithe ar chomhaontú a rinneadh lasmuigh de Catalyst. Is féidir le :agency do phróifíl a fheiceáil agus teachtaireachtaí a sheoladh chugat anois.',
            'unexpected' => 'Má tá an ceangal seo gan choinne, déan teagmháil le tacaíocht Catalyst le do thoil.',
            'cta' => 'Téigh chuig do dheais',
        ],
    ],
    'disconnected' => [
        'email' => [
            'subject' => 'Tá do cheangal le :counterparty ar Catalyst thart',
            'greeting' => 'Dia duit :name,',
            'body' => 'Chuir riarthóir Catalyst deireadh le do chaidreamh oibre le :counterparty. Níl sibh ceangailte ar an ardán a thuilleadh.',
            'unexpected' => 'Má tá sé seo gan choinne, déan teagmháil le tacaíocht Catalyst le do thoil.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Cuir bailchríoch ar shocrú do chuntais Catalyst',
            'greeting' => 'Dia duit :name,',
            'body' => 'Thosaigh tú ag cruthú do chuntas cruthaitheora ar Catalyst, ach níor dhearbhaigh tú do sheoladh ríomhphoist go fóill. Dearbhaigh anois é chun leanúint ar aghaidh ón áit ar stad tú agus do chlárú a chríochnú.',
            'cta' => 'Dearbhaigh mo ríomhphost',
            'expiry' => 'Rachaidh an nasc seo in éag i gceann :hours uair an chloig. Má théann sé in éag, is féidir leat ceann nua a iarraidh ón leathanach logála isteach.',
            'ignore' => 'Mura tusa a thosaigh an clárú seo, is féidir leat neamhaird a dhéanamh den ríomhphost seo.',
        ],
        'finish' => [
            'subject' => 'Cuir bailchríoch ar shocrú do phróifíle cruthaitheora Catalyst',
            'greeting' => 'Dia duit :name,',
            'body' => 'Thosaigh tú ag socrú do phróifíl chruthaitheora ar Catalyst, ach níor chríochnaigh tú go fóill í. Lean ar aghaidh ón áit ar stad tú chun do chlárú a chríochnú.',
            'cta' => 'Críochnaigh mo phróifíl',
            'ignore' => 'Má chríochnaigh tú do phróifíl cheana féin, is féidir leat neamhaird a dhéanamh den ríomhphost seo.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => 'D\'nuashonraigh :agency do stádas comhoibrithe ar Catalyst',
            'greeting' => 'Dia duit, :name,',
            'body' => 'D\'nuashonraigh :agency do stádas comhoibrithe ar Catalyst. Má tá aon cheist agat, déan teagmháil leo go díreach.',
            'closing' => 'Go raibh maith agat as bheith i do chuid de Catalyst.',
        ],
    ],
];
