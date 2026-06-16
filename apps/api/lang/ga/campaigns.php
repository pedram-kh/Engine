<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        'draft_submitted' => [
            'email' => [
                'subject' => 'Chuir :creator dréacht isteach le hathbhreithniú',
                'greeting' => 'Dia duit, :name,',
                'body' => 'Chuir :creator dréacht isteach do ":campaign". Oscail an feachtas agus faomhaigh é, iarr athruithe nó diúltaigh dó.',
                'cta' => 'Athbhreithnigh dréacht',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Ceadaíodh do dhréacht do :campaign',
                'subject_revision_requested' => 'Iarradh athruithe ar do dhréacht do :campaign',
                'subject_rejected' => 'Nuashonrú ar do dhréacht do :campaign',
                'greeting' => 'Dia duit, :name,',
                'body_approved' => 'Nuacht iontach — ceadaíodh do dhréacht do ":campaign". Is féidir leat foilsiú anois agus an nasc beo a sheoladh.',
                'body_revision_requested' => 'Tá an ghníomhaireacht ag iarraidh athruithe ar do dhréacht do ":campaign". Féach ar an aiseolas thíos agus seol arís é.',
                'body_rejected' => 'Tar éis athbhreithnithe, níor glacadh le do dhréacht do ":campaign" agus tá an cúram dúnta.',
                'feedback_label' => 'Aiseolas',
                'cta' => 'Féach ar chúram',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Theip ar fhíorú an phostála do :campaign',
                'greeting' => 'Dia duit, :name,',
                'body' => 'Níor éirigh linn postáil :creator a fhíorú go huathoibríoch do ":campaign". Athbhreithnigh an nasc a cuireadh isteach.',
                'reason_label' => 'Cad a tharla',
                'reason_not_found' => 'Ní bhfuarthas an postáil ag an nasc a cuireadh isteach.',
                'reason_mismatch' => 'Is cosúil nach mbaineann an postáil ag an nasc a cuireadh isteach leis an gcuntas ceangailte den chruthaitheoir.',
                'cta' => 'Athbhreithnigh cúram',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Glacadh le do phostáil do :campaign',
                'greeting' => 'Dia duit, :name,',
                'body' => 'Nuacht iontach — d\'athbhreithnigh an ghníomhaireacht do phostáil do ":campaign" agus ghlac sí leis. Níl aon ghníomh breise ag teastáil.',
                'cta' => 'Féach ar chúram',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Tá gníomh ag teastáil do do phostáil do :campaign',
                'greeting' => 'Dia duit, :name,',
                'body_fresh' => 'Níor éirigh leis an ngníomhaireacht do phostáil do ":campaign" a fhíorú agus tá sí ag iarraidh ort nasc nua a chur isteach. Oscail an cúram agus cuir isteach arís é.',
                'body_in_place' => 'Níor éirigh leis an ngníomhaireacht do phostáil do ":campaign" a fhíorú agus tá sí ag iarraidh ort an nasc a cuireadh isteach a cheartú. Oscail an cúram agus nuashonraigh é.',
                'feedback_label' => 'Nóta ón ngníomhaireacht',
                'cta' => 'Oscail cúram',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Tá an conradh do :campaign réidh',
                'greeting' => 'Dia duit, :name,',
                'body' => 'Tá an conradh do ":campaign" réidh le hathbhreithniú. Oscail an cúram, léigh na téarmaí agus glac leo.',
                'cta' => 'Athbhreithnigh conradh',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => 'Ghlac :creator leis an gconradh',
                'greeting' => 'Dia duit, :name,',
                'body' => 'Ghlac :creator leis an gconradh do ":campaign". Is féidir leo tosú ag obair ar a ndréacht anois.',
                'cta' => 'Féach ar fheachtas',
            ],
        ],
    ],
];
