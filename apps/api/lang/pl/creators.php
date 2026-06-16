<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Zostałeś zaproszony do :agency na Catalyst',
            'greeting' => 'Cześć,',
            'body' => ':agency zaprosiło Cię do dołączenia do swojego rosteru na Catalyst. Kliknij poniższy przycisk, aby skonfigurować swój profil twórcy.',
            'cta' => 'Zacznij',
            'expiry' => 'To zaproszenie wygasa :date.',
            'ignore' => 'Jeśli nie spodziewałeś się tego zaproszenia, możesz bezpiecznie zignorować tę wiadomość.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Twoja aplikacja Catalyst została zatwierdzona',
            'greeting' => 'Cześć, :name,',
            'body' => 'Dobra wiadomość — Twoja aplikacja twórcy została zatwierdzona. Masz teraz pełny dostęp do swojego pulpitu nawigacyjnego Catalyst.',
            'cta' => 'Przejdź do pulpitu nawigacyjnego',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Aktualizacja dotycząca Twojej aplikacji Catalyst',
            'greeting' => 'Cześć, :name,',
            'body' => 'Dziękujemy za aplikację do Catalyst. Po recenzji nie możemy teraz zatwierdzić Twojej aplikacji.',
            'reason_label' => 'Powód',
            'resubmit_hint' => 'Możesz zaktualizować swoją aplikację i przesłać ją ponownie do kolejnej recenzji z pulpitu nawigacyjnego.',
            'cta' => 'Przejrzyj swoją aplikację',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency chce się z Tobą połączyć na Catalyst',
            'greeting' => 'Cześć, :name,',
            'body' => ':agency chciałoby dodać Cię do swojego rosteru na Catalyst. Otwórz pulpit nawigacyjny, aby zaakceptować lub odrzucić prośbę.',
            'cta' => 'Wyświetl prośbę',
            'ignore' => 'Jeśli nie rozpoznajesz tej agencji, możesz po prostu odrzucić — nic się nie zmienia, dopóki nie zaakceptujesz.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency zaktualizowało Twój status współpracy na Catalyst',
            'greeting' => 'Cześć, :name,',
            'body' => ':agency zaktualizowało Twój status współpracy na Catalyst. Jeśli masz pytania, skontaktuj się z nimi bezpośrednio.',
            'closing' => 'Dziękujemy za bycie częścią Catalyst.',
        ],
    ],
];
