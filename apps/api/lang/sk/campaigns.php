<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator odoslal návrh na posúdenie',
                'greeting' => 'Dobrý deň, :name,',
                'body' => ':creator odoslal návrh pre ":campaign". Otvorte kampaň a schváľte ho, požiadajte o zmeny alebo ho zamietni.',
                'cta' => 'Skontrolovať návrh',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Váš návrh pre :campaign bol schválený',
                'subject_revision_requested' => 'Boli požadované zmeny vášho návrhu pre :campaign',
                'subject_rejected' => 'Aktualizácia vášho návrhu pre :campaign',
                'greeting' => 'Dobrý deň, :name,',
                'body_approved' => 'Skvelé správy — váš návrh pre ":campaign" bol schválený. Teraz ho môžete zverejniť a odoslať živý odkaz.',
                'body_revision_requested' => 'Agentúra požaduje zmeny vášho návrhu pre ":campaign". Prezrite si nižšie uvedenú spätnú väzbu a znovu odošlite.',
                'body_rejected' => 'Po posúdení váš návrh pre ":campaign" nebol prijatý a úloha bola uzavretá.',
                'feedback_label' => 'Spätná väzba',
                'cta' => 'Zobraziť úlohu',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Nepodarilo sa overiť príspevok pre :campaign',
                'greeting' => 'Dobrý deň, :name,',
                'body' => 'Nepodarilo sa nám automaticky overiť príspevok :creator pre ":campaign". Skontrolujte odoslaný odkaz.',
                'reason_label' => 'Čo sa stalo',
                'reason_not_found' => 'Príspevok nebol nájdený na odoslanom odkaze.',
                'reason_mismatch' => 'Príspevok na odoslanom odkaze sa zdá nepatriť k prepojenému účtu tvorcu.',
                'cta' => 'Skontrolovať úlohu',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Váš príspevok pre :campaign bol prijatý',
                'greeting' => 'Dobrý deň, :name,',
                'body' => 'Skvelé správy — agentúra skontrolovala a prijala váš príspevok pre ":campaign". Nie je potrebná žiadna ďalšia akcia.',
                'cta' => 'Zobraziť úlohu',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Je požadovaná akcia ohľadom vášho príspevku pre :campaign',
                'greeting' => 'Dobrý deň, :name,',
                'body_fresh' => 'Agentúra nemohla overiť váš príspevok pre ":campaign" a žiada vás o odoslanie nového odkazu. Otvorte úlohu a znovu odošlite.',
                'body_in_place' => 'Agentúra nemohla overiť váš príspevok pre ":campaign" a žiada vás o opravu odoslaného odkazu. Otvorte úlohu a aktualizujte ho.',
                'feedback_label' => 'Poznámka od agentúry',
                'cta' => 'Otvoriť úlohu',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Zmluva pre :campaign je pripravená',
                'greeting' => 'Dobrý deň, :name,',
                'body' => 'Zmluva pre ":campaign" je pripravená na vaše posúdenie. Otvorte úlohu, prečítajte si podmienky a prijmite ich.',
                'cta' => 'Skontrolovať zmluvu',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator prijal zmluvu',
                'greeting' => 'Dobrý deň, :name,',
                'body' => ':creator prijal zmluvu pre ":campaign". Teraz môžu začať pracovať na svojom návrhu.',
                'cta' => 'Zobraziť kampaň',
            ],
        ],
    ],
];
