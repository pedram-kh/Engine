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
    'admin_connected' => [
        'email' => [
            'subject' => 'Masz teraz połączenie z :agency w Catalyst',
            'greeting' => 'Cześć :name,',
            'body' => 'Administrator Catalyst połączył Cię z :agency na platformie na podstawie umowy zawartej poza Catalyst. :agency może teraz widzieć Twój profil i wysyłać Ci wiadomości.',
            'unexpected' => 'Jeśli to połączenie jest nieoczekiwane, skontaktuj się z pomocą techniczną Catalyst.',
            'cta' => 'Przejdź do panelu',
        ],
    ],
    'disconnected' => [
        'email' => [
            'subject' => 'Twoje połączenie z :counterparty w Catalyst zostało zakończone',
            'greeting' => 'Cześć :name,',
            'body' => 'Administrator Catalyst zakończył Twoją współpracę z :counterparty. Nie jesteście już połączeni na platformie.',
            'unexpected' => 'Jeśli to nieoczekiwane, skontaktuj się z pomocą techniczną Catalyst.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Dokończ konfigurację swojego konta Catalyst',
            'greeting' => 'Cześć :name,',
            'body' => 'Rozpocząłeś tworzenie konta twórcy w Catalyst, ale nie potwierdziłeś jeszcze swojego adresu e-mail. Potwierdź go teraz, aby kontynuować od miejsca, w którym skończyłeś, i dokończyć rejestrację.',
            'cta' => 'Potwierdź mój e-mail',
            'expiry' => 'Ten link wygaśnie za :hours godz. Jeśli wygaśnie, możesz poprosić o nowy na stronie logowania.',
            'ignore' => 'Jeśli to nie Ty rozpocząłeś tę rejestrację, możesz zignorować tę wiadomość.',
        ],
        'finish' => [
            'subject' => 'Dokończ konfigurację swojego profilu twórcy w Catalyst',
            'greeting' => 'Cześć :name,',
            'body' => 'Rozpocząłeś konfigurację profilu twórcy w Catalyst, ale jeszcze go nie ukończyłeś. Kontynuuj od miejsca, w którym skończyłeś, aby dokończyć rejestrację.',
            'cta' => 'Dokończ mój profil',
            'ignore' => 'Jeśli masz już ukończony profil, możesz zignorować tę wiadomość.',
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
