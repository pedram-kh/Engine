<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Juodraštis :n',
        'round_subject' => ':subject (:round)',
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator pateikė juodraštį peržiūrai',
                'greeting' => 'Sveiki, :name,',
                'body' => ':creator pateikė juodraštį kampanijai ":campaign". Atidarykite kampaniją ir patvirtinkite, paprašykite pakeitimų arba atmeskite.',
                'cta' => 'Peržiūrėti juodraštį',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Jūsų juodraštis kampanijai :campaign patvirtintas',
                'subject_revision_requested' => 'Paprašyta pakeitimų jūsų juodraštyje kampanijai :campaign',
                'subject_rejected' => 'Atnaujinimas apie jūsų juodraštį kampanijai :campaign',
                'greeting' => 'Sveiki, :name,',
                'body_approved' => 'Puikios žinios — jūsų juodraštis kampanijai ":campaign" patvirtintas. Dabar galite skelbti ir siųsti tiesioginę nuorodą.',
                'body_revision_requested' => 'Agentūra prašo pakeitimų jūsų juodraštyje kampanijai ":campaign". Peržiūrėkite žemiau pateiktą atsiliepimą ir pateikite iš naujo.',
                'body_rejected' => 'Po peržiūros jūsų juodraštis kampanijai ":campaign" nepriimtas ir užduotis uždaryta.',
                'feedback_label' => 'Atsiliepimai',
                'cta' => 'Žiūrėti užduotį',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Įrašo patikrinimas kampanijai :campaign nepavyko',
                'greeting' => 'Sveiki, :name,',
                'body' => 'Nepavyko automatiškai patikrinti :creator įrašo kampanijoje ":campaign". Peržiūrėkite pateiktą nuorodą.',
                'reason_label' => 'Kas atsitiko',
                'reason_not_found' => 'Įrašas nerastas pateiktoje nuorodoje.',
                'reason_mismatch' => 'Atrodo, kad įrašas pateiktoje nuorodoje nepriklauso susijusiai kūrėjo paskyrai.',
                'cta' => 'Peržiūrėti užduotį',
            ],
        ],
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Jūsų darbas kampanijai :campaign baigtas',
                'greeting' => 'Sveiki, :name,',
                'body' => 'Jūsų juodraštis kampanijai „:campaign“ patvirtintas. Šioje kampanijoje turinį paskelbia agentūra, todėl jūsų užduotis baigta — daugiau nieko daryti nereikia.',
                'cta' => 'Žiūrėti užduotį',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Jūsų įrašas kampanijai :campaign priimtas',
                'greeting' => 'Sveiki, :name,',
                'body' => 'Puikios žinios — agentūra peržiūrėjo ir priėmė jūsų įrašą kampanijai ":campaign". Tolesnių veiksmų nereikia.',
                'cta' => 'Žiūrėti užduotį',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Reikalingas veiksmas dėl jūsų įrašo kampanijai :campaign',
                'greeting' => 'Sveiki, :name,',
                'body_fresh' => 'Agentūra negalėjo patikrinti jūsų įrašo kampanijai ":campaign" ir prašo pateikti naują nuorodą. Atidarykite užduotį ir pateikite iš naujo.',
                'body_in_place' => 'Agentūra negalėjo patikrinti jūsų įrašo kampanijai ":campaign" ir prašo pataisyti pateiktą nuorodą. Atidarykite užduotį ir atnaujinkite ją.',
                'feedback_label' => 'Pastaba iš agentūros',
                'cta' => 'Atidaryti užduotį',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Sutartis kampanijai :campaign parengta',
                'greeting' => 'Sveiki, :name,',
                'body' => 'Sutartis kampanijai ":campaign" parengta jūsų peržiūrai. Atidarykite užduotį, perskaitykite sąlygas ir priimkite jas.',
                'cta' => 'Peržiūrėti sutartį',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator priėmė sutartį',
                'greeting' => 'Sveiki, :name,',
                'body' => ':creator priėmė sutartį kampanijai ":campaign". Jie dabar gali pradėti dirbti su savo juodraščiu.',
                'cta' => 'Žiūrėti kampaniją',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency paskelbė naują darbo pasiūlymą',
        'greeting' => 'Sveiki, :name,',
        'body' => ':agency jūsų skydelyje paskelbė naują darbo pasiūlymą: „:campaign“. Atidarykite jį, kad pamatytumėte išsamią informaciją ir pateiktumėte paraišką.',
        'cta' => 'Peržiūrėti pasiūlymą',
        'ignore' => 'Šį laišką gaunate, nes esate :agency kūrėjų sąraše.',
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
            'subject' => 'Nauja paraiška dėl :campaign',
            'greeting' => 'Sveiki, :name,',
            'body' => ':creator pateikė paraišką dėl „:campaign“. Atidarykite kampaniją, peržiūrėkite paraišką ir išsiųskite pasiūlymą.',
            'cta' => 'Peržiūrėti paraišką',
        ],
        'accepted' => [
            'subject' => 'Jūsų paraiška dėl :campaign priimta',
            'greeting' => 'Sveiki, :name,',
            'body' => ':agency priėmė jūsų paraišką dėl „:campaign“ ir išsiuntė jums pasiūlymą. Atidarykite užduotį, peržiūrėkite sąlygas ir pasiūlymą priimkite arba atmeskite.',
            'cta' => 'Peržiūrėti pasiūlymą',
        ],
        'rejected' => [
            'subject' => 'Naujienos apie jūsų paraišką dėl :campaign',
            'greeting' => 'Sveiki, :name,',
            'body_agency_rejected' => 'Dėkojame, kad pateikėte paraišką dėl „:campaign“. Šiam darbui nebuvote atrinkti. Nauji darbo pasiūlymai jūsų lentoje skelbiami reguliariai.',
            'body_campaign_closed' => 'Dėkojame, kad pateikėte paraišką dėl „:campaign“. Kampanija baigta, todėl jūsų paraiška toliau nebus svarstoma. Nauji darbo pasiūlymai jūsų lentoje skelbiami reguliariai.',
            'cta' => 'Peržiūrėti darbo pasiūlymus',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators vis dar turi kortelę paskelbimo stulpelyje. Perkelkite kortelę iš stulpelio, prieš išjungdami kūrėjų skelbimą.|[2,*] :count kortelės vis dar yra paskelbimo stulpelyje (:creators). Perkelkite jas iš stulpelio, prieš išjungdami kūrėjų skelbimą.',
    ],
];
