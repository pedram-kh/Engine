<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
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
];
