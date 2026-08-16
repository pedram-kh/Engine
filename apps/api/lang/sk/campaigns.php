<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Návrh :n',
        'round_subject' => ':subject (:round)',
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
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency zverejnila novú ponuku',
        'greeting' => 'Dobrý deň, :name,',
        'body' => ':agency zverejnila na vašej nástenke novú ponuku: „:campaign“. Otvorte ju, pozrite si podrobnosti a prihláste sa.',
        'cta' => 'Zobraziť ponuku',
        'ignore' => 'Túto správu dostávate, pretože ste na zozname tvorcov agentúry :agency.',
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
            'subject' => 'Nová prihláška na :campaign',
            'greeting' => 'Dobrý deň, :name,',
            'body' => ':creator sa prihlásil na „:campaign“. Otvorte kampaň, pozrite si prihlášku a pošlite ponuku.',
            'cta' => 'Zobraziť prihlášku',
        ],
        'accepted' => [
            'subject' => 'Vaša prihláška na :campaign bola prijatá',
            'greeting' => 'Dobrý deň, :name,',
            'body' => ':agency prijala vašu prihlášku na „:campaign“ a poslala vám ponuku. Otvorte zadanie, pozrite si podmienky a ponuku prijmite alebo odmietnite.',
            'cta' => 'Zobraziť ponuku',
        ],
        'rejected' => [
            'subject' => 'Novinky k vašej prihláške na :campaign',
            'greeting' => 'Dobrý deň, :name,',
            'body_agency_rejected' => 'Ďakujeme za vašu prihlášku na „:campaign“. Na túto prácu ste neboli vybraní. Nové ponuky sa na vašej tabuli objavujú pravidelne.',
            'body_campaign_closed' => 'Ďakujeme za vašu prihlášku na „:campaign“. Kampaň bola ukončená, takže vaša prihláška nebude posudzovaná ďalej. Nové ponuky sa na vašej tabuli objavujú pravidelne.',
            'cta' => 'Zobraziť pracovné ponuky',
        ],
    ],
];
