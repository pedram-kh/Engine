<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator odeslal návrh k posouzení',
                'greeting' => 'Dobrý den, :name,',
                'body' => ':creator odeslal návrh pro ":campaign". Otevřete kampaň a schvalte ho, požádejte o změny nebo ho zamítněte.',
                'cta' => 'Zkontrolovat návrh',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Váš návrh pro :campaign byl schválen',
                'subject_revision_requested' => 'Byly požadovány změny vašeho návrhu pro :campaign',
                'subject_rejected' => 'Aktualizace vašeho návrhu pro :campaign',
                'greeting' => 'Dobrý den, :name,',
                'body_approved' => 'Skvělé zprávy — váš návrh pro ":campaign" byl schválen. Nyní ho můžete zveřejnit a odeslat živý odkaz.',
                'body_revision_requested' => 'Agentura požaduje změny vašeho návrhu pro ":campaign". Prohlédněte si níže uvedenou zpětnou vazbu a znovu odešlete.',
                'body_rejected' => 'Po posouzení váš návrh pro ":campaign" nebyl přijat a úkol byl uzavřen.',
                'feedback_label' => 'Zpětná vazba',
                'cta' => 'Zobrazit úkol',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Nepodařilo se ověřit příspěvek pro :campaign',
                'greeting' => 'Dobrý den, :name,',
                'body' => 'Nepodařilo se nám automaticky ověřit příspěvek :creator pro ":campaign". Zkontrolujte odeslaný odkaz.',
                'reason_label' => 'Co se stalo',
                'reason_not_found' => 'Příspěvek nebyl nalezen na odeslaném odkazu.',
                'reason_mismatch' => 'Příspěvek na odeslaném odkazu se zdá nepatřit k propojenému účtu tvůrce.',
                'cta' => 'Zkontrolovat úkol',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Váš příspěvek pro :campaign byl přijat',
                'greeting' => 'Dobrý den, :name,',
                'body' => 'Skvělé zprávy — agentura zkontrolovala a přijala váš příspěvek pro ":campaign". Není potřeba žádná další akce.',
                'cta' => 'Zobrazit úkol',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Je vyžadována akce ohledně vašeho příspěvku pro :campaign',
                'greeting' => 'Dobrý den, :name,',
                'body_fresh' => 'Agentura nemohla ověřit váš příspěvek pro ":campaign" a žádá vás o odeslání nového odkazu. Otevřete úkol a znovu odešlete.',
                'body_in_place' => 'Agentura nemohla ověřit váš příspěvek pro ":campaign" a žádá vás o opravu odeslaného odkazu. Otevřete úkol a aktualizujte ho.',
                'feedback_label' => 'Poznámka od agentury',
                'cta' => 'Otevřít úkol',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Smlouva pro :campaign je připravena',
                'greeting' => 'Dobrý den, :name,',
                'body' => 'Smlouva pro ":campaign" je připravena k vašemu posouzení. Otevřete úkol, přečtěte si podmínky a přijměte je.',
                'cta' => 'Zkontrolovat smlouvu',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator přijal smlouvu',
                'greeting' => 'Dobrý den, :name,',
                'body' => ':creator přijal smlouvu pro ":campaign". Nyní mohou začít pracovat na svém návrhu.',
                'cta' => 'Zobrazit kampaň',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency zveřejnila novou nabídku',
        'greeting' => 'Dobrý den, :name,',
        'body' => ':agency zveřejnila na vaší nástěnce novou nabídku: „:campaign“. Otevřete ji, prohlédněte si podrobnosti a přihlaste se.',
        'cta' => 'Zobrazit nabídku',
        'ignore' => 'Tuto zprávu dostáváte, protože jste na seznamu tvůrců agentury :agency.',
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
            'subject' => 'Nová přihláška na :campaign',
            'greeting' => 'Dobrý den, :name,',
            'body' => ':creator se přihlásil na „:campaign“. Otevřete kampaň, prohlédněte si přihlášku a pošlete nabídku.',
            'cta' => 'Zobrazit přihlášku',
        ],
        'accepted' => [
            'subject' => 'Vaše přihláška na :campaign byla přijata',
            'greeting' => 'Dobrý den, :name,',
            'body' => ':agency přijala vaši přihlášku na „:campaign“ a poslala vám nabídku. Otevřete zadání, prohlédněte si podmínky a nabídku přijměte nebo odmítněte.',
            'cta' => 'Zobrazit nabídku',
        ],
        'rejected' => [
            'subject' => 'Novinky k vaší přihlášce na :campaign',
            'greeting' => 'Dobrý den, :name,',
            'body_agency_rejected' => 'Děkujeme za vaši přihlášku na „:campaign“. Pro tuto práci jste nebyli vybráni. Nové nabídky se na vaší nástěnce objevují pravidelně.',
            'body_campaign_closed' => 'Děkujeme za vaši přihlášku na „:campaign“. Kampaň byla ukončena, takže vaše přihláška nebude dále posuzována. Nové nabídky se na vaší nástěnce objevují pravidelně.',
            'cta' => 'Zobrazit nabídky práce',
        ],
    ],
];
