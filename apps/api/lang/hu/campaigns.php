<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
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
];
