<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Hasło musi być ciągiem znaków.',
        'too_short' => 'Hasło musi mieć co najmniej :min znaków.',
        'too_long' => 'Hasło nie może przekraczać :max znaków.',
        'breached' => 'To hasło zostało ujawnione w znanych wyciekach danych i nie może być użyte. Wybierz inne hasło.',
    ],

    'login' => [
        'invalid_credentials' => 'Nieprawidłowy adres e-mail lub hasło.',
        'mfa_required' => 'Wymagane jest uwierzytelnianie dwuskładnikowe, aby dokończyć logowanie.',
        'account_locked_temporary' => 'Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za :minutes minut.',
        'account_locked' => 'To konto zostało zablokowane. Zresetuj hasło lub skontaktuj się z pomocą techniczną, aby odzyskać dostęp.',
        'rate_limited' => 'Zbyt wiele żądań. Spróbuj ponownie za :seconds sekund.',
        'wrong_spa' => 'To konto nie jest zarejestrowane dla tej witryny. Zaloguj się w odpowiedniej witrynie.',
    ],

    'reset' => [
        'subject' => 'Zresetuj hasło do :app',
        'greeting' => 'Cześć, :name,',
        'body' => 'Otrzymaliśmy prośbę o zresetowanie hasła do Twojego konta :app. Poniższy link jest ważny przez :minutes minut.',
        'cta' => 'Zresetuj hasło',
        'ignore' => 'Jeśli nie prosiłeś o to, możesz bezpiecznie zignorować tę wiadomość — Twoje hasło nie zostanie zmienione.',
        'invalid_token' => 'Ten link do resetowania hasła jest nieprawidłowy lub wygasł. Poproś o nowy.',
        'completed' => 'Twoje hasło zostało zresetowane. Wszystkie inne aktywne sesje zostały wylogowane.',
    ],

    'email_verification' => [
        'subject' => 'Zweryfikuj adres e-mail :app',
        'greeting' => 'Witaj w :app, :name!',
        'body' => 'Potwierdź swój adres e-mail, aby dokończyć konfigurację konta :app. Poniższy link jest ważny przez :hours godzin.',
        'cta' => 'Zweryfikuj adres e-mail',
        'ignore' => 'Jeśli nie zakładałeś konta :app, możesz bezpiecznie zignorować tę wiadomość.',
        'verification_invalid' => 'Ten link weryfikacyjny jest nieprawidłowy. Poproś o nowy.',
        'verification_expired' => 'Ten link weryfikacyjny wygasł. Poproś o nowy.',
        'already_verified' => 'Ten adres e-mail został już zweryfikowany.',
    ],

    'signup' => [
        'email_taken' => 'Konto z tym adresem e-mail już istnieje. Zaloguj się, aby z niego korzystać, lub zresetuj hasło. Jeśli poprosiłeś o usunięcie tego konta, logując się, możesz je przywrócić.',
    ],

    'mfa' => [
        'invalid_code' => 'Kod dwuskładnikowy jest nieprawidłowy. Spróbuj ponownie.',
        'rate_limited' => 'Zbyt wiele nieprawidłowych prób dwuskładnikowych. Spróbuj ponownie za :minutes minut.',
        'enrollment_suspended' => 'Uwierzytelnianie dwuskładnikowe zostało zawieszone dla tego konta. Skontaktuj się z pomocą techniczną, aby przywrócić dostęp.',
        'enrollment_required' => 'Uwierzytelnianie dwuskładnikowe musi być włączone przed kontynuowaniem.',
        'already_enabled' => 'Uwierzytelnianie dwuskładnikowe jest już włączone dla tego konta.',
        'not_enabled' => 'Uwierzytelnianie dwuskładnikowe nie jest włączone dla tego konta.',
        'provisional_expired' => 'Sesja rejestracji dwuskładnikowej wygasła. Zacznij od nowa.',
    ],

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'Twoje uwierzytelnianie dwuskładnikowe zostało zresetowane',
            'greeting' => 'Cześć :name,',
            'body' => 'Administrator platformy zresetował uwierzytelnianie dwuskładnikowe na Twoim koncie :app. Twoja dotychczasowa aplikacja uwierzytelniająca i kody odzyskiwania już nie działają.',
            'next' => 'Przy następnym wejściu do chronionej części aplikacji zostaniesz poproszony o ponowną konfigurację uwierzytelniania dwuskładnikowego.',
            'unexpected' => 'Jeśli o to nie prosiłeś, natychmiast skontaktuj się z pomocą techniczną.',
            'cta' => 'Przejdź do :app',
        ],
        'exempted' => [
            'subject' => 'Uwierzytelnianie dwuskładnikowe nie jest już wymagane na Twoim koncie',
            'greeting' => 'Cześć :name,',
            'body' => 'Administrator platformy usunął wymóg uwierzytelniania dwuskładnikowego z Twojego konta :app. Miejsca, które wcześniej prosiły o jego konfigurację, możesz teraz używać bez niej.',
            'still_enrolled' => 'Jeśli masz już skonfigurowane uwierzytelnianie dwuskładnikowe, pozostaje aktywne i przy logowaniu nadal będziesz proszony o kod — możesz je zachować lub wyłączyć w ustawieniach.',
            'unexpected' => 'Jeśli nie spodziewałeś się tej zmiany, skontaktuj się z pomocą techniczną.',
        ],
        'exemption_removed' => [
            'subject' => 'Uwierzytelnianie dwuskładnikowe jest znów wymagane na Twoim koncie',
            'greeting' => 'Cześć :name,',
            'body' => 'Administrator platformy przywrócił wymóg uwierzytelniania dwuskładnikowego na Twoim koncie :app.',
            'next' => 'Przy następnym wejściu do chronionej części zostaniesz poproszony o konfigurację uwierzytelniania dwuskładnikowego, jeśli jeszcze go nie masz.',
            'unexpected' => 'Jeśli nie spodziewałeś się tej zmiany, skontaktuj się z pomocą techniczną.',
        ],
    ],
    'deletion_mail' => [
        'subject' => 'Twoje konto :app zostało zaplanowane do usunięcia',
        'greeting' => 'Cześć, :name,',
        'body' => 'Otrzymaliśmy Twoją prośbę o usunięcie konta :app. Zostanie ono trwale usunięte :date i potem nie będzie można tego cofnąć.',
        'keep' => 'Do tego czasu Twoje konto jest zablokowane i nic nie zostało usunięte. Aby je zachować, zaloguj się przed tą datą swoim adresem e-mail i hasłem i wybierz przywrócenie.',
        'unexpected' => 'Jeśli to nie Ty o to prosiłeś, ktoś inny może znać Twoje hasło. Przywróć konto, logując się, a następnie zmień hasło.',
        'support' => 'Pytania? Napisz na :support.',
    ],
];
