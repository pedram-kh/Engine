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
];
