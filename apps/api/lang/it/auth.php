<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'La password deve essere una stringa.',
        'too_short' => 'La password deve contenere almeno :min caratteri.',
        'too_long' => 'La password non deve superare :max caratteri.',
        'breached' => 'Questa password compare in violazioni di dati note e non può essere utilizzata. Scegline una diversa.',
    ],

    'login' => [
        'invalid_credentials' => 'Email o password non validi.',
        'mfa_required' => "L'autenticazione a più fattori è richiesta per completare l'accesso.",
        'account_locked_temporary' => 'Troppi tentativi di accesso falliti. Riprova tra :minutes minuti.',
        'account_locked' => "Questo account è stato bloccato. Reimposta la password o contatta l'assistenza per ripristinare l'accesso.",
        'rate_limited' => 'Troppe richieste. Riprova tra :seconds secondi.',
        'wrong_spa' => 'Questo account non è registrato per questo sito. Accedi sul sito corretto.',
    ],

    'reset' => [
        'subject' => 'Reimposta la tua password :app',
        'greeting' => 'Ciao :name,',
        'body' => 'Abbiamo ricevuto una richiesta di reimpostazione della password per il tuo account :app. Il link qui sotto è valido per :minutes minuti.',
        'cta' => 'Reimposta password',
        'ignore' => 'Se non hai richiesto questa modifica, puoi ignorare questa email — la tua password non verrà modificata.',
        'invalid_token' => 'Questo link di reimpostazione della password non è valido o è scaduto. Richiedine uno nuovo.',
        'completed' => 'La tua password è stata reimpostata. Tutte le altre sessioni attive sono state terminate.',
    ],

    'email_verification' => [
        'subject' => 'Verifica il tuo indirizzo email :app',
        'greeting' => 'Benvenuto su :app, :name!',
        'body' => 'Conferma il tuo indirizzo email per completare la configurazione del tuo account :app. Il link qui sotto è valido per :hours ore.',
        'cta' => 'Verifica indirizzo email',
        'ignore' => 'Se non hai creato un account :app, puoi ignorare questa email.',
        'verification_invalid' => 'Questo link di verifica non è valido. Richiedine uno nuovo.',
        'verification_expired' => 'Questo link di verifica è scaduto. Richiedine uno nuovo.',
        'already_verified' => 'Questo indirizzo email è già stato verificato.',
    ],

    'signup' => [
        'email_taken' => 'Esiste già un account con questo indirizzo email.',
    ],

    'mfa' => [
        'invalid_code' => 'Il codice a due fattori non è valido. Riprova.',
        'rate_limited' => 'Troppi tentativi non validi del codice a due fattori. Riprova tra :minutes minuti.',
        'enrollment_suspended' => "L'autenticazione a due fattori è stata sospesa per questo account. Contatta l'assistenza per ripristinare l'accesso.",
        'enrollment_required' => "L'autenticazione a due fattori deve essere abilitata prima di poter continuare.",
        'already_enabled' => "L'autenticazione a due fattori è già abilitata per questo account.",
        'not_enabled' => "L'autenticazione a due fattori non è abilitata per questo account.",
        'provisional_expired' => 'La sessione di registrazione a due fattori è scaduta. Riprova.',
    ],

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'La tua autenticazione a due fattori è stata reimpostata',
            'greeting' => 'Ciao :name,',
            'body' => "Un amministratore della piattaforma ha reimpostato l'autenticazione a due fattori del tuo account :app. La tua precedente app di autenticazione e i codici di recupero non funzionano più.",
            'next' => "Ti verrà chiesto di configurare di nuovo l'autenticazione a due fattori al prossimo accesso a una parte protetta dell'applicazione.",
            'unexpected' => "Se non hai richiesto questa operazione, contatta subito l'assistenza.",
            'cta' => 'Vai su :app',
        ],
        'exempted' => [
            'subject' => "L'autenticazione a due fattori non è più obbligatoria sul tuo account",
            'greeting' => 'Ciao :name,',
            'body' => "Un amministratore della piattaforma ha rimosso l'obbligo di autenticazione a due fattori dal tuo account :app. Ora puoi usare senza configurarla le aree che prima te la richiedevano.",
            'still_enrolled' => "Se hai già configurato l'autenticazione a due fattori, resta attiva e al momento dell'accesso ti verrà comunque chiesto un codice: puoi mantenerla o disattivarla nelle impostazioni.",
            'unexpected' => "Se non ti aspettavi questa modifica, contatta l'assistenza.",
        ],
        'exemption_removed' => [
            'subject' => "L'autenticazione a due fattori è di nuovo obbligatoria sul tuo account",
            'greeting' => 'Ciao :name,',
            'body' => "Un amministratore della piattaforma ha ripristinato l'obbligo di autenticazione a due fattori sul tuo account :app.",
            'next' => "Al prossimo accesso a un'area protetta ti verrà chiesto di configurare l'autenticazione a due fattori, se non lo hai già fatto.",
            'unexpected' => "Se non ti aspettavi questa modifica, contatta l'assistenza.",
        ],
    ],
];
