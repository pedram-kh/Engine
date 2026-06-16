<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Das Passwort muss eine Zeichenkette sein.',
        'too_short' => 'Das Passwort muss mindestens :min Zeichen lang sein.',
        'too_long' => 'Das Passwort darf nicht länger als :max Zeichen sein.',
        'breached' => 'Dieses Passwort ist aus bekannten Datenlecks bekannt und kann nicht verwendet werden. Bitte wähle ein anderes.',
    ],

    'login' => [
        'invalid_credentials' => 'Ungültige E-Mail-Adresse oder ungültiges Passwort.',
        'mfa_required' => 'Zur Anmeldung ist eine Multi-Faktor-Authentifizierung erforderlich.',
        'account_locked_temporary' => 'Zu viele fehlgeschlagene Anmeldeversuche. Bitte versuche es in :minutes Minuten erneut.',
        'account_locked' => 'Dieses Konto wurde gesperrt. Setze dein Passwort zurück oder wende dich an den Support, um wieder Zugang zu erhalten.',
        'rate_limited' => 'Zu viele Anfragen. Bitte versuche es in :seconds Sekunden erneut.',
        'wrong_spa' => 'Dieses Konto ist für diese Website nicht registriert. Bitte melde dich auf der richtigen Website an.',
    ],

    'reset' => [
        'subject' => 'Setze dein :app-Passwort zurück',
        'greeting' => 'Hallo :name,',
        'body' => 'Wir haben eine Anfrage erhalten, das Passwort deines :app-Kontos zurückzusetzen. Der unten stehende Link ist :minutes Minuten gültig.',
        'cta' => 'Passwort zurücksetzen',
        'ignore' => 'Wenn du diese Anfrage nicht gestellt hast, kannst du diese E-Mail ignorieren – dein Passwort wird nicht geändert.',
        'invalid_token' => 'Dieser Link zum Zurücksetzen des Passworts ist ungültig oder abgelaufen. Bitte fordere einen neuen an.',
        'completed' => 'Dein Passwort wurde zurückgesetzt. Alle anderen aktiven Sitzungen wurden abgemeldet.',
    ],

    'email_verification' => [
        'subject' => 'Bestätige deine :app-E-Mail-Adresse',
        'greeting' => 'Willkommen bei :app, :name!',
        'body' => 'Bitte bestätige deine E-Mail-Adresse, um die Einrichtung deines :app-Kontos abzuschließen. Der unten stehende Link ist :hours Stunden gültig.',
        'cta' => 'E-Mail-Adresse bestätigen',
        'ignore' => 'Wenn du kein :app-Konto erstellt hast, kannst du diese E-Mail ignorieren.',
        'verification_invalid' => 'Dieser Bestätigungslink ist ungültig. Bitte fordere einen neuen an.',
        'verification_expired' => 'Dieser Bestätigungslink ist abgelaufen. Bitte fordere einen neuen an.',
        'already_verified' => 'Diese E-Mail-Adresse wurde bereits bestätigt.',
    ],

    'signup' => [
        'email_taken' => 'Ein Konto mit dieser E-Mail-Adresse existiert bereits.',
    ],

    'mfa' => [
        'invalid_code' => 'Der Zwei-Faktor-Code ist ungültig. Bitte versuche es erneut.',
        'rate_limited' => 'Zu viele ungültige Zwei-Faktor-Versuche. Bitte versuche es in :minutes Minuten erneut.',
        'enrollment_suspended' => 'Die Zwei-Faktor-Authentifizierung wurde für dieses Konto gesperrt. Bitte wende dich an den Support, um den Zugang wiederherzustellen.',
        'enrollment_required' => 'Die Zwei-Faktor-Authentifizierung muss aktiviert werden, bevor du fortfahren kannst.',
        'already_enabled' => 'Die Zwei-Faktor-Authentifizierung ist für dieses Konto bereits aktiviert.',
        'not_enabled' => 'Die Zwei-Faktor-Authentifizierung ist für dieses Konto nicht aktiviert.',
        'provisional_expired' => 'Die Einrichtungssitzung für die Zwei-Faktor-Authentifizierung ist abgelaufen. Bitte beginne erneut.',
    ],
];
