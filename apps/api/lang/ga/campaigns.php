<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Dréacht :n',
        'round_subject' => ':subject (:round)',
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
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Tá do chuid oibre le haghaidh :campaign críochnaithe',
                'greeting' => 'Dia duit, :name,',
                'body' => 'Ceadaíodh do dhréacht le haghaidh ":campaign". Ar an bhfeachtas seo, foilsíonn an ghníomhaireacht an t-ábhar, mar sin tá do thasc críochnaithe anois — níl aon rud eile le déanamh agat.',
                'cta' => 'Féach ar chúram',
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
        'invite_received' => [
            'email' => [
                'subject_fresh' => 'Tá tairiscint nua agat do :campaign',
                'subject_re_offer' => 'Tairiscint nuashonraithe do :campaign',
                'greeting' => 'Dia duit, :name,',
                'body_fresh' => 'Tugadh cuireadh duit obair a dhéanamh ar ":campaign". Oscail an cúram chun an tairiscint a athbhreithniú agus freagra a thabhairt.',
                'body_re_offer' => 'Tá tairiscint nuashonraithe ag fanacht leat ar ":campaign". Oscail an cúram chun í a athbhreithniú agus freagra a thabhairt.',
                'cta' => 'Féach ar an tairiscint',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => 'D’fhoilsigh :agency post nua',
        'greeting' => 'Dia duit, :name,',
        'body' => 'D’fhoilsigh :agency post nua ar do chlár: “:campaign”. Oscail é chun na sonraí a fheiceáil agus iarratas a dhéanamh.',
        'cta' => 'Féach ar an bpost',
        'ignore' => 'Tá an teachtaireacht seo á fáil agat toisc go bhfuil tú ar liosta cruthaitheoirí :agency.',
    ],
    // AH-058 (Jobs Board chunk 4, D6) — the three application mails. All three
    // are queued and localized at queue time to the recipient's
    // preferred_language (a worker has no request locale), and all three are
    // gated by the `application_notifications_enabled` Pennant flag on the MAIL
    // leg only — the in-app rows write regardless.
    //
    // `rejected` carries TWO body variants selected by
    // ApplicationRejectionCause (`body_agency_rejected` / `body_campaign_closed`)
    // under ONE subject, the draft-reviewed `body_ . $outcome` precedent: the
    // recipient's question is the same either way, and two mailables would double
    // 24 locales of copy to express one sentence of difference.
    //
    // ⚠ No agency-supplied reason exists anywhere in the reject copy, by design
    // (D4): none is collected or stored, and the audit row plus its actor is the
    // internal record.
    'campaign_application' => [
        'submitted' => [
            'subject' => 'Iarratas nua ar :campaign',
            'greeting' => 'Dia duit, :name,',
            'body' => 'Chuir :creator iarratas isteach ar ":campaign". Oscail an feachtas chun an iarratas a mheas agus tairiscint a sheoladh.',
            'cta' => 'Féach ar an iarratas',
        ],
        'accepted' => [
            'subject' => 'Glacadh le d\'iarratas ar :campaign',
            'greeting' => 'Dia duit, :name,',
            'body' => 'Ghlac :agency le d\'iarratas ar ":campaign" agus sheol tairiscint chugat. Oscail an tasc chun na téarmaí a léamh agus glacadh leis nó é a dhiúltú.',
            'cta' => 'Féach ar an tairiscint',
        ],
        'rejected' => [
            'subject' => 'Nuacht faoi d\'iarratas ar :campaign',
            'greeting' => 'Dia duit, :name,',
            'body_agency_rejected' => 'Go raibh maith agat as iarratas a chur isteach ar ":campaign". Níor roghnaíodh tú don phost seo. Foilsítear poist nua ar do chlár go rialta.',
            'body_campaign_closed' => 'Go raibh maith agat as iarratas a chur isteach ar ":campaign". Tá an feachtas dúnta, mar sin ní rachaidh d\'iarratas ar aghaidh. Foilsítear poist nua ar do chlár go rialta.',
            'cta' => 'Féach ar an gclár post',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} Tá cárta ag :creators fós sa cholún foilsithe. Bog an cárta as an gcolún sula gcuirtear foilsiú ag cruthaitheoirí as.|[2,*] Tá :count cárta fós sa cholún foilsithe (:creators). Bog amach as an gcolún iad sula gcuirtear foilsiú ag cruthaitheoirí as.',
    ],
];
