<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Чернова :n',
        'round_subject' => ':subject (:round)',
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator изпрати чернова за преглед',
                'greeting' => 'Здравей, :name,',
                'body' => ':creator изпрати чернова за ":campaign". Отворете кампанията и я одобрете, поискайте промени или я отхвърлете.',
                'cta' => 'Преглед на черновата',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Вашата чернова за :campaign е одобрена',
                'subject_revision_requested' => 'Поискани са промени по черновата ви за :campaign',
                'subject_rejected' => 'Актуализация на черновата ви за :campaign',
                'greeting' => 'Здравей, :name,',
                'body_approved' => 'Страхотни новини — черновата ви за ":campaign" е одобрена. Вече можете да публикувате и изпратите живата връзка.',
                'body_revision_requested' => 'Агенцията иска промени по черновата ви за ":campaign". Прегледайте обратната връзка по-долу и изпратете отново.',
                'body_rejected' => 'След преглед, черновата ви за ":campaign" не е приета и задачата е затворена.',
                'feedback_label' => 'Обратна връзка',
                'cta' => 'Виж задачата',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Проверката на публикацията за :campaign е неуспешна',
                'greeting' => 'Здравей, :name,',
                'body' => 'Не можахме автоматично да верифицираме публикацията на :creator за ":campaign". Прегледайте изпратената връзка.',
                'reason_label' => 'Какво се случи',
                'reason_not_found' => 'Публикацията не е намерена на изпратената връзка.',
                'reason_mismatch' => 'Публикацията на изпратената връзка изглежда не принадлежи на свързания акаунт на твореца.',
                'cta' => 'Прегледай задачата',
            ],
        ],
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Работата ви по :campaign е завършена',
                'greeting' => 'Здравей, :name,',
                'body' => 'Черновата ви за „:campaign“ беше одобрена. При тази кампания агенцията публикува съдържанието, така че заданието ви вече е завършено — не е необходимо да правите нищо повече.',
                'cta' => 'Виж задачата',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Вашата публикация за :campaign е приета',
                'greeting' => 'Здравей, :name,',
                'body' => 'Страхотни новини — агенцията е прегледала и приела вашата публикация за ":campaign". Не се изискват допълнителни действия.',
                'cta' => 'Виж задачата',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Необходимо е действие за вашата публикация за :campaign',
                'greeting' => 'Здравей, :name,',
                'body_fresh' => 'Агенцията не успя да верифицира вашата публикация за ":campaign" и иска да изпратите нова връзка. Отворете задачата и изпратете отново.',
                'body_in_place' => 'Агенцията не успя да верифицира вашата публикация за ":campaign" и иска да коригирате изпратената връзка. Отворете задачата и я актуализирайте.',
                'feedback_label' => 'Бележка от агенцията',
                'cta' => 'Отвори задачата',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Договорът за :campaign е готов',
                'greeting' => 'Здравей, :name,',
                'body' => 'Договорът за ":campaign" е готов за вашия преглед. Отворете задачата, прочетете условията и ги приемете.',
                'cta' => 'Прегледай договора',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator прие договора',
                'greeting' => 'Здравей, :name,',
                'body' => ':creator прие договора за ":campaign". Вече могат да започнат работа по черновата си.',
                'cta' => 'Виж кампанията',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency публикува нова обява',
        'greeting' => 'Здравейте, :name,',
        'body' => ':agency публикува нова обява във вашето табло: „:campaign“. Отворете я, за да видите подробностите и да кандидатствате.',
        'cta' => 'Вижте обявата',
        'ignore' => 'Получавате това съобщение, защото сте в списъка с творци на :agency.',
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
            'subject' => 'Нова кандидатура за :campaign',
            'greeting' => 'Здравейте, :name,',
            'body' => ':creator кандидатства за „:campaign“. Отворете кампанията, за да прегледате кандидатурата и да изпратите оферта.',
            'cta' => 'Преглед на кандидатурата',
        ],
        'accepted' => [
            'subject' => 'Вашата кандидатура за :campaign беше приета',
            'greeting' => 'Здравейте, :name,',
            'body' => ':agency прие вашата кандидатура за „:campaign“ и ви изпрати оферта. Отворете задачата, за да прегледате условията и да приемете или откажете.',
            'cta' => 'Преглед на офертата',
        ],
        'rejected' => [
            'subject' => 'Новини за вашата кандидатура за :campaign',
            'greeting' => 'Здравейте, :name,',
            'body_agency_rejected' => 'Благодарим ви, че кандидатствахте за „:campaign“. Не бяхте избрани за тази работа. Нови обяви се публикуват редовно на вашия борд.',
            'body_campaign_closed' => 'Благодарим ви, че кандидатствахте за „:campaign“. Кампанията беше затворена, така че вашата кандидатура няма да продължи напред. Нови обяви се публикуват редовно на вашия борд.',
            'cta' => 'Към борда с обяви',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators все още има карта в колоната за публикуване. Преместете картата извън колоната, преди да изключите публикуването от създатели.|[2,*] :count карти са все още в колоната за публикуване (:creators). Преместете ги извън колоната, преди да изключите публикуването от създатели.',
    ],
];
