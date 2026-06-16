<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
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
];
