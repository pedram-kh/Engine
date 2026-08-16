<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Ciorna :n',
        'round_subject' => ':subject (:round)',
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator a trimis o ciornă pentru revizuire',
                'greeting' => 'Bună ziua, :name,',
                'body' => ':creator a trimis o ciornă pentru ":campaign". Deschideți campania și aprobați-o, solicitați modificări sau respingeți-o.',
                'cta' => 'Revizuiți ciorna',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Ciorna dvs. pentru :campaign a fost aprobată',
                'subject_revision_requested' => 'Au fost solicitate modificări la ciorna dvs. pentru :campaign',
                'subject_rejected' => 'Actualizare privind ciorna dvs. pentru :campaign',
                'greeting' => 'Bună ziua, :name,',
                'body_approved' => 'Vești excelente — ciorna dvs. pentru ":campaign" a fost aprobată. Acum puteți publica și trimite linkul live.',
                'body_revision_requested' => 'Agenția solicită modificări la ciorna dvs. pentru ":campaign". Revizuiți feedback-ul de mai jos și retrimiteți.',
                'body_rejected' => 'După revizuire, ciorna dvs. pentru ":campaign" nu a fost acceptată și sarcina este închisă.',
                'feedback_label' => 'Feedback',
                'cta' => 'Vizualizați sarcina',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Verificarea postării pentru :campaign a eșuat',
                'greeting' => 'Bună ziua, :name,',
                'body' => 'Nu am putut verifica automat postarea lui :creator pentru ":campaign". Revizuiți linkul trimis.',
                'reason_label' => 'Ce s-a întâmplat',
                'reason_not_found' => 'Postarea nu a fost găsită la linkul trimis.',
                'reason_mismatch' => 'Postarea de la linkul trimis pare să nu aparțină contului conectat al creatorului.',
                'cta' => 'Revizuiți sarcina',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Postarea dvs. pentru :campaign a fost acceptată',
                'greeting' => 'Bună ziua, :name,',
                'body' => 'Vești excelente — agenția a revizuit și acceptat postarea dvs. pentru ":campaign". Nu sunt necesare acțiuni suplimentare.',
                'cta' => 'Vizualizați sarcina',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Este necesară o acțiune pentru postarea dvs. pentru :campaign',
                'greeting' => 'Bună ziua, :name,',
                'body_fresh' => 'Agenția nu a putut verifica postarea dvs. pentru ":campaign" și solicită să trimiteți un link nou. Deschideți sarcina și retrimiteți.',
                'body_in_place' => 'Agenția nu a putut verifica postarea dvs. pentru ":campaign" și solicită să corectați linkul trimis. Deschideți sarcina și actualizați-l.',
                'feedback_label' => 'Notă de la agenție',
                'cta' => 'Deschideți sarcina',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Contractul pentru :campaign este gata',
                'greeting' => 'Bună ziua, :name,',
                'body' => 'Contractul pentru ":campaign" este gata pentru revizuirea dvs. Deschideți sarcina, citiți termenii și acceptați-i.',
                'cta' => 'Revizuiți contractul',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator a acceptat contractul',
                'greeting' => 'Bună ziua, :name,',
                'body' => ':creator a acceptat contractul pentru ":campaign". Acum pot începe să lucreze la ciorna lor.',
                'cta' => 'Vizualizați campania',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency a publicat un job nou',
        'greeting' => 'Bună, :name,',
        'body' => ':agency a publicat un job nou pe panoul tău: „:campaign”. Deschide-l pentru a vedea detaliile și a aplica.',
        'cta' => 'Vezi jobul',
        'ignore' => 'Primești acest mesaj pentru că te afli pe lista de creatori a agenției :agency.',
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
            'subject' => 'Candidatură nouă pentru :campaign',
            'greeting' => 'Bună, :name,',
            'body' => ':creator a aplicat la „:campaign”. Deschide campania pentru a analiza candidatura și a trimite o ofertă.',
            'cta' => 'Vezi candidatura',
        ],
        'accepted' => [
            'subject' => 'Candidatura ta pentru :campaign a fost acceptată',
            'greeting' => 'Bună, :name,',
            'body' => ':agency a acceptat candidatura ta pentru „:campaign” și ți-a trimis o ofertă. Deschide sarcina pentru a citi condițiile și a accepta sau refuza oferta.',
            'cta' => 'Vezi oferta',
        ],
        'rejected' => [
            'subject' => 'Noutăți despre candidatura ta pentru :campaign',
            'greeting' => 'Bună, :name,',
            'body_agency_rejected' => 'Îți mulțumim pentru candidatura la „:campaign”. Nu ai fost selectat pentru acest job. Joburi noi apar regulat pe panoul tău.',
            'body_campaign_closed' => 'Îți mulțumim pentru candidatura la „:campaign”. Campania s-a încheiat, așa că candidatura ta nu va merge mai departe. Joburi noi apar regulat pe panoul tău.',
            'cta' => 'Vezi anunțurile de job',
        ],
    ],
];
