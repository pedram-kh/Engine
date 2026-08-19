<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Szkic :n',
        'round_subject' => ':subject (:round)',
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator przesłał szkic do recenzji',
                'greeting' => 'Cześć, :name,',
                'body' => ':creator przesłał szkic dla ":campaign". Otwórz kampanię, aby go zatwierdzić, poprosić o zmiany lub odrzucić.',
                'cta' => 'Przejrzyj szkic',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Twój szkic dla :campaign został zatwierdzony',
                'subject_revision_requested' => 'Zażądano zmian w szkicu :campaign',
                'subject_rejected' => 'Aktualizacja dotycząca szkicu :campaign',
                'greeting' => 'Cześć, :name,',
                'body_approved' => 'Dobra wiadomość — Twój szkic dla ":campaign" został zatwierdzony. Możesz go teraz opublikować i przesłać link do aktywnego posta.',
                'body_revision_requested' => 'Agencja zażądała zmian w Twoim szkicu dla ":campaign". Przejrzyj poniższe uwagi i prześlij ponownie.',
                'body_rejected' => 'Po recenzji Twój szkic dla ":campaign" nie został zaakceptowany, a zadanie zostało zamknięte.',
                'feedback_label' => 'Uwagi',
                'cta' => 'Wyświetl zadanie',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Nie można zweryfikować posta dla :campaign',
                'greeting' => 'Cześć, :name,',
                'body' => 'Nie udało nam się automatycznie zweryfikować posta :creator dla ":campaign". Przejrzyj przesłany link.',
                'reason_label' => 'Co się stało',
                'reason_not_found' => 'Post nie został znaleziony pod przesłanym linkiem.',
                'reason_mismatch' => 'Post pod przesłanym linkiem nie wydaje się należeć do połączonego konta twórcy.',
                'cta' => 'Przejrzyj zadanie',
            ],
        ],
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Twoja praca dla :campaign jest ukończona',
                'greeting' => 'Cześć, :name,',
                'body' => 'Twoja wersja robocza dla „:campaign” została zatwierdzona. W tej kampanii materiał publikuje agencja, więc Twoje zlecenie jest już ukończone — nie musisz nic więcej robić.',
                'cta' => 'Wyświetl zadanie',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Twój post dla :campaign został zaakceptowany',
                'greeting' => 'Cześć, :name,',
                'body' => 'Dobra wiadomość — agencja przejrzała i zaakceptowała Twój post dla ":campaign". Nie są wymagane dalsze działania.',
                'cta' => 'Wyświetl zadanie',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Wymagane działanie dotyczące Twojego posta dla :campaign',
                'greeting' => 'Cześć, :name,',
                'body_fresh' => 'Agencja nie mogła zweryfikować Twojego posta dla ":campaign" i prosi o przesłanie nowego linku do posta. Otwórz zadanie, aby przesłać ponownie.',
                'body_in_place' => 'Agencja nie mogła zweryfikować Twojego posta dla ":campaign" i prosi o poprawienie przesłanego linku. Otwórz zadanie, aby go zaktualizować.',
                'feedback_label' => 'Uwaga od agencji',
                'cta' => 'Otwórz zadanie',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Umowa do :campaign jest gotowa',
                'greeting' => 'Cześć, :name,',
                'body' => 'Umowa dla ":campaign" jest gotowa do Twojej recenzji. Otwórz zadanie, aby przeczytać warunki i zaakceptować.',
                'cta' => 'Przejrzyj umowę',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator zaakceptował umowę',
                'greeting' => 'Cześć, :name,',
                'body' => ':creator zaakceptował umowę dla ":campaign". Mogą teraz rozpocząć pracę nad swoim szkicem.',
                'cta' => 'Wyświetl kampanię',
            ],
        ],
        'invite_received' => [
            'email' => [
                'subject_fresh' => 'Masz nową ofertę dla :campaign',
                'subject_re_offer' => 'Zaktualizowana oferta dla :campaign',
                'greeting' => 'Cześć, :name,',
                'body_fresh' => 'Zostałeś zaproszony do pracy nad ":campaign". Otwórz zadanie, aby zapoznać się z ofertą i odpowiedzieć.',
                'body_re_offer' => 'Na ":campaign" czeka na Ciebie zaktualizowana oferta. Otwórz zadanie, aby się z nią zapoznać i odpowiedzieć.',
                'cta' => 'Zobacz ofertę',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency opublikowała nowe zlecenie',
        'greeting' => 'Cześć :name,',
        'body' => ':agency opublikowała nowe zlecenie na Twojej tablicy: „:campaign”. Otwórz je, aby zobaczyć szczegóły i zgłosić się.',
        'cta' => 'Zobacz zlecenie',
        'ignore' => 'Otrzymujesz tę wiadomość, ponieważ znajdujesz się na liście twórców agencji :agency.',
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
            'subject' => 'Nowe zgłoszenie do :campaign',
            'greeting' => 'Cześć :name,',
            'body' => ':creator zgłosił się do „:campaign”. Otwórz kampanię, aby przejrzeć zgłoszenie i wysłać ofertę.',
            'cta' => 'Przejrzyj zgłoszenie',
        ],
        'accepted' => [
            'subject' => 'Twoje zgłoszenie do :campaign zostało zaakceptowane',
            'greeting' => 'Cześć :name,',
            'body' => ':agency zaakceptowała Twoje zgłoszenie do „:campaign” i wysłała Ci ofertę. Otwórz zlecenie, sprawdź warunki i zaakceptuj je lub odrzuć.',
            'cta' => 'Zobacz ofertę',
        ],
        'rejected' => [
            'subject' => 'Nowości w sprawie Twojego zgłoszenia do :campaign',
            'greeting' => 'Cześć :name,',
            'body_agency_rejected' => 'Dziękujemy za zgłoszenie do „:campaign”. Nie zostałeś wybrany do tego zlecenia. Nowe oferty pojawiają się na Twojej tablicy regularnie.',
            'body_campaign_closed' => 'Dziękujemy za zgłoszenie do „:campaign”. Kampania została zamknięta, więc Twoje zgłoszenie nie będzie rozpatrywane dalej. Nowe oferty pojawiają się na Twojej tablicy regularnie.',
            'cta' => 'Zobacz oferty pracy',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators nadal ma kartę w kolumnie publikacji. Przenieś kartę poza kolumnę, zanim wyłączysz publikowanie przez twórców.|[2,*] :count kart nadal znajduje się w kolumnie publikacji (:creators). Przenieś je poza kolumnę, zanim wyłączysz publikowanie przez twórców.',
    ],
];
