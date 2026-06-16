<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
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
];
