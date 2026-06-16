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
        'email_taken' => 'Konto z tym adresem e-mail już istnieje.',
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
];
