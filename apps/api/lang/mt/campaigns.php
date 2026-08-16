<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Abbozz :n',
        'round_subject' => ':subject (:round)',
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator bagħat abbozz għar-reviżjoni',
                'greeting' => 'Bonġu, :name,',
                'body' => ':creator bagħat abbozz għal ":campaign". Iftaħ il-kampanja u approva, itlob bidliet jew irrifjuta.',
                'cta' => 'Rrevedi l-abbozz',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'L-abbozz tiegħek għal :campaign ġie approvat',
                'subject_revision_requested' => 'Intalbu bidliet fl-abbozz tiegħek għal :campaign',
                'subject_rejected' => 'Aġġornament dwar l-abbozz tiegħek għal :campaign',
                'greeting' => 'Bonġu, :name,',
                'body_approved' => 'Aħbarijiet sbieħ — l-abbozz tiegħek għal ":campaign" ġie approvat. Issa tista\' tippubblika u tibgħat il-link live.',
                'body_revision_requested' => 'L-aġenzija qed titlob bidliet fl-abbozz tiegħek għal ":campaign". Rrevedi l-feedback hawn taħt u erġa\' ibgħat.',
                'body_rejected' => 'Wara reviżjoni, l-abbozz tiegħek għal ":campaign" ma ġiex aċċettat u l-kompitu huwa magħluq.',
                'feedback_label' => 'Feedback',
                'cta' => 'Ara l-kompitu',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Il-verifika tal-post għal :campaign faliiet',
                'greeting' => 'Bonġu, :name,',
                'body' => 'Ma stajtx nivverifika awtomatikament il-post ta\' :creator għal ":campaign". Rrevedi l-link mibgħut.',
                'reason_label' => 'X\'ġara',
                'reason_not_found' => 'Il-post ma nstabx fil-link mibgħut.',
                'reason_mismatch' => 'Il-post fil-link mibgħut jidher li ma jkunx tal-kont ikkollegat tal-kreatur.',
                'cta' => 'Rrevedi l-kompitu',
            ],
        ],
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Ix-xogħol tiegħek għal :campaign lest',
                'greeting' => 'Bonġu, :name,',
                'body' => 'L-abbozz tiegħek għal ":campaign" ġie approvat. F’din il-kampanja l-kontenut jiġi ppubblikat mill-aġenzija, għalhekk l-inkarigu tiegħek issa lest — ma hemmx x’tagħmel aktar.',
                'cta' => 'Ara l-kompitu',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Il-post tiegħek għal :campaign ġie aċċettat',
                'greeting' => 'Bonġu, :name,',
                'body' => 'Aħbarijiet sbieħ — l-aġenzija rrevediet u aċċettat il-post tiegħek għal ":campaign". M\'hemmx bżonn ta\' aktar azzjonijiet.',
                'cta' => 'Ara l-kompitu',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Azzjoni meħtieġa għall-post tiegħek għal :campaign',
                'greeting' => 'Bonġu, :name,',
                'body_fresh' => 'L-aġenzija ma setgħetx tivverifika l-post tiegħek għal ":campaign" u qed titlob li tibgħat link ġdid. Iftaħ il-kompitu u erġa\' ibgħat.',
                'body_in_place' => 'L-aġenzija ma setgħetx tivverifika l-post tiegħek għal ":campaign" u qed titlob li tissewwa l-link mibgħut. Iftaħ il-kompitu u aġġornah.',
                'feedback_label' => 'Nota mill-aġenzija',
                'cta' => 'Iftaħ il-kompitu',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Il-kuntratt għal :campaign huwa lest',
                'greeting' => 'Bonġu, :name,',
                'body' => 'Il-kuntratt għal ":campaign" huwa lest għar-reviżjoni tiegħek. Iftaħ il-kompitu, aqra t-termini u aċċettahom.',
                'cta' => 'Rrevedi l-kuntratt',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator aċċetta l-kuntratt',
                'greeting' => 'Bonġu, :name,',
                'body' => ':creator aċċetta l-kuntratt għal ":campaign". Issa jistgħu jibdew jaħdmu fuq l-abbozz tagħhom.',
                'cta' => 'Ara l-kampanja',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency ippubblika xogħol ġdid',
        'greeting' => 'Bonġu :name,',
        'body' => ':agency ippubblika xogħol ġdid fuq il-bord tiegħek: “:campaign”. Iftħu biex tara d-dettalji u tapplika.',
        'cta' => 'Ara x-xogħol',
        'ignore' => 'Qed tirċievi dan il-messaġġ għax tinsab fil-lista ta’ kreaturi ta’ :agency.',
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
            'subject' => 'Applikazzjoni ġdida għal :campaign',
            'greeting' => 'Bonġu :name,',
            'body' => ':creator applika għal ":campaign". Iftaħ il-kampanja biex tirrevedi l-applikazzjoni u tibgħat offerta.',
            'cta' => 'Irrevedi l-applikazzjoni',
        ],
        'accepted' => [
            'subject' => 'L-applikazzjoni tiegħek għal :campaign ġiet aċċettata',
            'greeting' => 'Bonġu :name,',
            'body' => ':agency aċċettat l-applikazzjoni tiegħek għal ":campaign" u bagħtitlek offerta. Iftaħ l-inkarigu biex taqra l-kundizzjonijiet u taċċetta jew tirrifjuta.',
            'cta' => 'Ara l-offerta',
        ],
        'rejected' => [
            'subject' => 'Aġġornament dwar l-applikazzjoni tiegħek għal :campaign',
            'greeting' => 'Bonġu :name,',
            'body_agency_rejected' => 'Grazzi talli applikajt għal ":campaign". Ma ntgħażiltx għal dan ix-xogħol. Xogħlijiet ġodda jiġu ppubblikati regolarment fuq il-bord tiegħek.',
            'body_campaign_closed' => 'Grazzi talli applikajt għal ":campaign". Il-kampanja ngħalqet, għalhekk l-applikazzjoni tiegħek mhux se timxi quddiem. Xogħlijiet ġodda jiġu ppubblikati regolarment fuq il-bord tiegħek.',
            'cta' => 'Ara l-bord tax-xogħol',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators għad għandu karta fil-kolonna tal-pubblikazzjoni. Neħħi l-karta mill-kolonna qabel ma titfi l-pubblikazzjoni mill-kreaturi.|[2,*] :count karti għadhom fil-kolonna tal-pubblikazzjoni (:creators). Neħħihom mill-kolonna qabel ma titfi l-pubblikazzjoni mill-kreaturi.',
    ],
];
