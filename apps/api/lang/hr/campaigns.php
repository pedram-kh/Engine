<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Nacrt :n',
        'round_subject' => ':subject (:round)',
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator je poslao nacrt na pregled',
                'greeting' => 'Pozdrav, :name,',
                'body' => ':creator je poslao nacrt za ":campaign". Otvorite kampanju i odobrite ga, zatražite izmjene ili odbijte.',
                'cta' => 'Pregled nacrta',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Vaš nacrt za :campaign je odobren',
                'subject_revision_requested' => 'Zatražene su izmjene vašeg nacrta za :campaign',
                'subject_rejected' => 'Ažuriranje vašeg nacrta za :campaign',
                'greeting' => 'Pozdrav, :name,',
                'body_approved' => 'Odlične vijesti — vaš nacrt za ":campaign" je odobren. Sada ga možete objaviti i poslati živu vezu.',
                'body_revision_requested' => 'Agencija traži izmjene vašeg nacrta za ":campaign". Pregledajte povratne informacije u nastavku i ponovo pošaljite.',
                'body_rejected' => 'Nakon pregleda, vaš nacrt za ":campaign" nije prihvaćen i zadatak je zatvoren.',
                'feedback_label' => 'Povratne informacije',
                'cta' => 'Pogledaj zadatak',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Provjera objave za :campaign nije uspjela',
                'greeting' => 'Pozdrav, :name,',
                'body' => 'Nismo mogli automatski verificirati objavu :creator za ":campaign". Pregledajte poslanu vezu.',
                'reason_label' => 'Što se dogodilo',
                'reason_not_found' => 'Objava nije pronađena na poslanoj vezi.',
                'reason_mismatch' => 'Objava na poslanoj vezi čini se da ne pripada povezanom računu kreatora.',
                'cta' => 'Pregledaj zadatak',
            ],
        ],
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Vaš rad za :campaign je dovršen',
                'greeting' => 'Pozdrav, :name,',
                'body' => 'Vaša skica za „:campaign“ je odobrena. U ovoj kampanji sadržaj objavljuje agencija, pa je vaš zadatak sada dovršen — ništa više ne morate učiniti.',
                'cta' => 'Pogledaj zadatak',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Vaša objava za :campaign je prihvaćena',
                'greeting' => 'Pozdrav, :name,',
                'body' => 'Odlične vijesti — agencija je pregledala i prihvatila vašu objavu za ":campaign". Nisu potrebne daljnje radnje.',
                'cta' => 'Pogledaj zadatak',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Potrebna je radnja za vašu objavu za :campaign',
                'greeting' => 'Pozdrav, :name,',
                'body_fresh' => 'Agencija nije mogla verificirati vašu objavu za ":campaign" i traži da pošaljete novu vezu. Otvorite zadatak i ponovo pošaljite.',
                'body_in_place' => 'Agencija nije mogla verificirati vašu objavu za ":campaign" i traži da ispravite poslanu vezu. Otvorite zadatak i ažurirajte je.',
                'feedback_label' => 'Napomena od agencije',
                'cta' => 'Otvori zadatak',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Ugovor za :campaign je spreman',
                'greeting' => 'Pozdrav, :name,',
                'body' => 'Ugovor za ":campaign" spreman je za vaš pregled. Otvorite zadatak, pročitajte uvjete i prihvatite ih.',
                'cta' => 'Pregledaj ugovor',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator je prihvatio ugovor',
                'greeting' => 'Pozdrav, :name,',
                'body' => ':creator je prihvatio ugovor za ":campaign". Sada mogu početi raditi na svom nacrtu.',
                'cta' => 'Pogledaj kampanju',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency je objavila novi posao',
        'greeting' => 'Pozdrav, :name,',
        'body' => ':agency je na vašoj ploči objavila novi posao: „:campaign“. Otvorite ga da vidite pojedinosti i prijavite se.',
        'cta' => 'Pogledaj posao',
        'ignore' => 'Ovu poruku primate jer se nalazite na popisu kreatora agencije :agency.',
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
            'greeting' => 'Pozdrav, :name,',
            'body' => ':creator se prijavio na „:campaign“. Otvorite kampanju kako biste pregledali prijavu i poslali ponudu.',
            'cta' => 'Pregledaj prijavu',
        ],
        'accepted' => [
            'subject' => 'Vaša prijava za :campaign je prihvaćena',
            'greeting' => 'Pozdrav, :name,',
            'body' => ':agency je prihvatila vašu prijavu za „:campaign“ i poslala vam ponudu. Otvorite zadatak, pregledajte uvjete te prihvatite ili odbijte ponudu.',
            'cta' => 'Pregledaj ponudu',
        ],
        'rejected' => [
            'subject' => 'Novosti o vašoj prijavi za :campaign',
            'greeting' => 'Pozdrav, :name,',
            'body_agency_rejected' => 'Hvala vam na prijavi za „:campaign“. Za ovaj posao niste odabrani. Novi poslovi redovno se objavljuju na vašoj ploči.',
            'body_campaign_closed' => 'Hvala vam na prijavi za „:campaign“. Kampanja je zatvorena, pa vaša prijava neće ići dalje. Novi poslovi redovno se objavljuju na vašoj ploči.',
            'cta' => 'Pregledaj ponude poslova',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators još uvijek ima karticu u stupcu objave. Premjestite karticu iz stupca prije nego isključite objavu autora.|[2,*] :count kartica još je u stupcu objave (:creators). Premjestite ih iz stupca prije nego isključite objavu autora.',
    ],
];
