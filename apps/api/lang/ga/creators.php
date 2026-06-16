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
    'blacklisted' => [
        'email' => [
            'subject' => 'D\'nuashonraigh :agency do stádas comhoibrithe ar Catalyst',
            'greeting' => 'Dia duit, :name,',
            'body' => 'D\'nuashonraigh :agency do stádas comhoibrithe ar Catalyst. Má tá aon cheist agat, déan teagmháil leo go díreach.',
            'closing' => 'Go raibh maith agat as bheith i do chuid de Catalyst.',
        ],
    ],
];
