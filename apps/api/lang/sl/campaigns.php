<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Osnutek :n',
        'round_subject' => ':subject (:round)',
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator je oddal osnutek v pregled',
                'greeting' => 'Pozdravljeni, :name,',
                'body' => ':creator je oddal osnutek za ":campaign". Odprite kampanjo in ga odobrite, zahtevajte spremembe ali zavrnite.',
                'cta' => 'Preglej osnutek',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Vaš osnutek za :campaign je bil odobren',
                'subject_revision_requested' => 'Zahtevane so bile spremembe vašega osnutka za :campaign',
                'subject_rejected' => 'Posodobitev vašega osnutka za :campaign',
                'greeting' => 'Pozdravljeni, :name,',
                'body_approved' => 'Odlične novice — vaš osnutek za ":campaign" je bil odobren. Zdaj ga lahko objavite in pošljete živo povezavo.',
                'body_revision_requested' => 'Agencija zahteva spremembe vašega osnutka za ":campaign". Preglejte spodnjo povratno informacijo in znova oddajte.',
                'body_rejected' => 'Po pregledu vaš osnutek za ":campaign" ni bil sprejet in naloga je bila zaprta.',
                'feedback_label' => 'Povratna informacija',
                'cta' => 'Poglej nalogo',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Preverjanje objave za :campaign ni uspelo',
                'greeting' => 'Pozdravljeni, :name,',
                'body' => 'Samodejno preverjanje objave :creator za ":campaign" ni uspelo. Preverite predloženo povezavo.',
                'reason_label' => 'Kaj se je zgodilo',
                'reason_not_found' => 'Objava ni bila najdena na predloženi povezavi.',
                'reason_mismatch' => 'Objava na predloženi povezavi se zdi, da ne pripada povezanemu računu ustvarjalca.',
                'cta' => 'Preveri nalogo',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Vaša objava za :campaign je bila sprejeta',
                'greeting' => 'Pozdravljeni, :name,',
                'body' => 'Odlične novice — agencija je pregledala in sprejela vašo objavo za ":campaign". Ni potrebnih nadaljnjih ukrepov.',
                'cta' => 'Poglej nalogo',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Za vašo objavo za :campaign je potrebno ukrepanje',
                'greeting' => 'Pozdravljeni, :name,',
                'body_fresh' => 'Agencija ni mogla preveriti vaše objave za ":campaign" in vas prosi, da pošljete novo povezavo. Odprite nalogo in znova oddajte.',
                'body_in_place' => 'Agencija ni mogla preveriti vaše objave za ":campaign" in vas prosi, da popravite predloženo povezavo. Odprite nalogo in jo posodobite.',
                'feedback_label' => 'Opomba agencije',
                'cta' => 'Odpri nalogo',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Pogodba za :campaign je pripravljena',
                'greeting' => 'Pozdravljeni, :name,',
                'body' => 'Pogodba za ":campaign" je pripravljena za vaš pregled. Odprite nalogo, preberite pogoje in jih sprejmite.',
                'cta' => 'Preglej pogodbo',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator je sprejel pogodbo',
                'greeting' => 'Pozdravljeni, :name,',
                'body' => ':creator je sprejel pogodbo za ":campaign". Zdaj lahko začnejo delati na svojem osnutku.',
                'cta' => 'Poglej kampanjo',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency je objavila novo delo',
        'greeting' => 'Pozdravljeni, :name,',
        'body' => ':agency je na vaši plošči objavila novo delo: »:campaign«. Odprite ga, da si ogledate podrobnosti in se prijavite.',
        'cta' => 'Ogled dela',
        'ignore' => 'To sporočilo prejemate, ker ste na seznamu ustvarjalcev agencije :agency.',
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
            'subject' => 'Nova prijava za :campaign',
            'greeting' => 'Pozdravljeni, :name,',
            'body' => ':creator se je prijavil na „:campaign“. Odprite kampanjo, preglejte prijavo in pošljite ponudbo.',
            'cta' => 'Preglej prijavo',
        ],
        'accepted' => [
            'subject' => 'Vaša prijava za :campaign je bila sprejeta',
            'greeting' => 'Pozdravljeni, :name,',
            'body' => ':agency je sprejela vašo prijavo za „:campaign“ in vam poslala ponudbo. Odprite nalogo, preglejte pogoje ter ponudbo sprejmite ali zavrnite.',
            'cta' => 'Poglej ponudbo',
        ],
        'rejected' => [
            'subject' => 'Novice o vaši prijavi za :campaign',
            'greeting' => 'Pozdravljeni, :name,',
            'body_agency_rejected' => 'Hvala za vašo prijavo na „:campaign“. Za to delo niste bili izbrani. Nova dela se na vaši plošči objavljajo redno.',
            'body_campaign_closed' => 'Hvala za vašo prijavo na „:campaign“. Kampanja je zaključena, zato vaša prijava ne bo obravnavana naprej. Nova dela se na vaši plošči objavljajo redno.',
            'cta' => 'Poglej ponudbe dela',
        ],
    ],
];
