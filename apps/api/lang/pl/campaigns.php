<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
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
    ],
];
