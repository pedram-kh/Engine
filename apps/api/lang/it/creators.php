<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Sei stato invitato a :agency su Catalyst',
            'greeting' => 'Ciao,',
            'body' => ':agency ti ha invitato a unirti al loro roster su Catalyst. Clicca sul pulsante qui sotto per configurare il tuo profilo creator.',
            'cta' => 'Inizia',
            'expiry' => 'Questo invito scade il :date.',
            'ignore' => 'Se non ti aspettavi questo invito, puoi ignorare questa email.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'La tua candidatura a Catalyst è stata approvata',
            'greeting' => 'Ciao :name,',
            'body' => 'Buone notizie — la tua candidatura come creator è stata approvata. Ora hai pieno accesso alla tua dashboard di Catalyst.',
            'cta' => 'Vai alla tua dashboard',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Un aggiornamento sulla tua candidatura a Catalyst',
            'greeting' => 'Ciao :name,',
            'body' => 'Grazie per esserti candidato a Catalyst. Dopo la revisione, non possiamo approvare la tua candidatura in questo momento.',
            'reason_label' => 'Motivo',
            'resubmit_hint' => 'Puoi aggiornare la tua candidatura e inviarla di nuovo per una nuova revisione dalla tua dashboard.',
            'cta' => 'Rivedi la tua candidatura',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency vuole connettersi con te su Catalyst',
            'greeting' => 'Ciao :name,',
            'body' => ':agency vorrebbe aggiungerti al loro roster su Catalyst. Apri la tua dashboard per accettare o rifiutare la richiesta.',
            'cta' => 'Vedi la richiesta',
            'ignore' => 'Se non riconosci questa agenzia, puoi semplicemente rifiutare — nulla cambia finché non accetti.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Completa la configurazione del tuo account Catalyst',
            'greeting' => 'Ciao :name,',
            'body' => 'Hai iniziato a creare il tuo account creator su Catalyst, ma non hai ancora confermato il tuo indirizzo email. Confermalo ora per riprendere da dove avevi lasciato e completare la registrazione.',
            'cta' => 'Conferma la tua email',
            'expiry' => 'Questo link scade tra :hours ore. Se scade, puoi richiederne uno nuovo dalla pagina di accesso.',
            'ignore' => 'Se non hai avviato tu questa registrazione, puoi ignorare questa email.',
        ],
        'finish' => [
            'subject' => 'Completa la configurazione del tuo profilo creator su Catalyst',
            'greeting' => 'Ciao :name,',
            'body' => 'Hai iniziato a configurare il tuo profilo creator su Catalyst, ma non hai ancora finito. Riprendi da dove avevi lasciato per completare la registrazione.',
            'cta' => 'Completa il tuo profilo',
            'ignore' => 'Se hai già completato il tuo profilo, puoi ignorare questa email.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency ha aggiornato il tuo stato di collaborazione su Catalyst',
            'greeting' => 'Ciao :name,',
            'body' => ':agency ha aggiornato il tuo stato di collaborazione su Catalyst. Per qualsiasi domanda, contattali direttamente.',
            'closing' => 'Grazie per far parte di Catalyst.',
        ],
    ],
];
