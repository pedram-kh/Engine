<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
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
];
