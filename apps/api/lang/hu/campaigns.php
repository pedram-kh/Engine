<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => ':n. vázlat',
        'round_subject' => ':subject (:round)',
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator beküldte a vázlatot ellenőrzésre',
                'greeting' => 'Kedves :name,',
                'body' => ':creator vázlatot küldött a(z) ":campaign" kampányhoz. Nyissa meg a kampányt, és hagyja jóvá, kérjen módosításokat vagy utasítsa el.',
                'cta' => 'Vázlat megtekintése',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'A(z) :campaign kampányhoz tartozó vázlata jóváhagyva',
                'subject_revision_requested' => 'Módosítások kérése a(z) :campaign kampány vázlatához',
                'subject_rejected' => 'Frissítés a(z) :campaign kampány vázlatáról',
                'greeting' => 'Kedves :name,',
                'body_approved' => 'Nagyszerű hírek — a(z) ":campaign" kampányhoz tartozó vázlata jóváhagyva. Most már közzéteheti és elküldheti az élő linket.',
                'body_revision_requested' => 'Az ügynökség módosításokat kér a(z) ":campaign" kampányhoz tartozó vázlatához. Tekintse meg az alábbi visszajelzést, és küldje be újra.',
                'body_rejected' => 'Az ellenőrzés után a(z) ":campaign" kampányhoz tartozó vázlata nem fogadható el, és a feladat lezárult.',
                'feedback_label' => 'Visszajelzés',
                'cta' => 'Feladat megtekintése',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'A(z) :campaign kampány bejegyzésének ellenőrzése sikertelen',
                'greeting' => 'Kedves :name,',
                'body' => 'Nem sikerült automatikusan ellenőrizni :creator bejegyzését a(z) ":campaign" kampányhoz. Tekintse meg a beküldött linket.',
                'reason_label' => 'Mi történt',
                'reason_not_found' => 'A bejegyzés nem található a beküldött linken.',
                'reason_mismatch' => 'Úgy tűnik, hogy a beküldött linken lévő bejegyzés nem tartozik az alkotó csatlakoztatott fiókjához.',
                'cta' => 'Feladat megtekintése',
            ],
        ],
        'completed_on_approval' => [
            'email' => [
                'subject' => 'A :campaign kampányhoz végzett munkája elkészült',
                'greeting' => 'Kedves :name,',
                'body' => 'A „:campaign” kampányhoz beadott vázlatát jóváhagyták. Ebben a kampányban a tartalmat az ügynökség teszi közzé, így a feladata befejeződött — nincs további tennivalója.',
                'cta' => 'Feladat megtekintése',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'A(z) :campaign kampányhoz tartozó bejegyzése elfogadva',
                'greeting' => 'Kedves :name,',
                'body' => 'Nagyszerű hírek — az ügynökség áttekintette és elfogadta a(z) ":campaign" kampányhoz tartozó bejegyzését. Nincs szükség további intézkedésekre.',
                'cta' => 'Feladat megtekintése',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Intézkedés szükséges a(z) :campaign kampányhoz tartozó bejegyzéséhez',
                'greeting' => 'Kedves :name,',
                'body_fresh' => 'Az ügynökség nem tudta ellenőrizni a(z) ":campaign" kampányhoz tartozó bejegyzését, és kéri, hogy küldjön be új linket. Nyissa meg a feladatot, és küldje be újra.',
                'body_in_place' => 'Az ügynökség nem tudta ellenőrizni a(z) ":campaign" kampányhoz tartozó bejegyzését, és kéri a beküldött link javítását. Nyissa meg a feladatot, és frissítse.',
                'feedback_label' => 'Megjegyzés az ügynökségtől',
                'cta' => 'Feladat megnyitása',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'A(z) :campaign kampányhoz tartozó szerződés készen áll',
                'greeting' => 'Kedves :name,',
                'body' => 'A(z) ":campaign" kampányhoz tartozó szerződés készen áll az Ön ellenőrzésére. Nyissa meg a feladatot, olvassa el a feltételeket, és fogadja el azokat.',
                'cta' => 'Szerződés megtekintése',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator elfogadta a szerződést',
                'greeting' => 'Kedves :name,',
                'body' => ':creator elfogadta a(z) ":campaign" kampányhoz tartozó szerződést. Most elkezdhetik a vázlatukon dolgozni.',
                'cta' => 'Kampány megtekintése',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => 'A(z) :agency új munkát hirdetett meg',
        'greeting' => 'Szia :name,',
        'body' => 'A(z) :agency új munkát hirdetett meg a tábládon: „:campaign”. Nyisd meg a részletekért, és jelentkezz.',
        'cta' => 'Munka megtekintése',
        'ignore' => 'Azért kapod ezt az üzenetet, mert szerepelsz a(z) :agency alkotói listáján.',
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
            'subject' => 'Új jelentkezés a következőre: :campaign',
            'greeting' => 'Szia :name,',
            'body' => ':creator jelentkezett a következőre: „:campaign“. Nyisd meg a kampányt, tekintsd át a jelentkezést, és küldj ajánlatot.',
            'cta' => 'Jelentkezés megtekintése',
        ],
        'accepted' => [
            'subject' => 'A jelentkezésedet elfogadták a következőre: :campaign',
            'greeting' => 'Szia :name,',
            'body' => 'A(z) :agency elfogadta a jelentkezésedet a következőre: „:campaign“, és ajánlatot küldött neked. Nyisd meg a feladatot, tekintsd át a feltételeket, majd fogadd el vagy utasítsd el.',
            'cta' => 'Ajánlat megtekintése',
        ],
        'rejected' => [
            'subject' => 'Hír a jelentkezésedről: :campaign',
            'greeting' => 'Szia :name,',
            'body_agency_rejected' => 'Köszönjük a jelentkezésedet a következőre: „:campaign“. Erre a munkára nem téged választottak. Új munkák rendszeresen megjelennek a tábládon.',
            'body_campaign_closed' => 'Köszönjük a jelentkezésedet a következőre: „:campaign“. A kampány lezárult, ezért a jelentkezésed nem kerül tovább. Új munkák rendszeresen megjelennek a tábládon.',
            'cta' => 'Álláshirdetések megtekintése',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators kártyája még a közzétételi oszlopban van. Helyezze át a kártyát az oszlopból, mielőtt kikapcsolja az alkotói közzétételt.|[2,*] :count kártya még a közzétételi oszlopban van (:creators). Helyezze át őket az oszlopból, mielőtt kikapcsolja az alkotói közzétételt.',
    ],
];
