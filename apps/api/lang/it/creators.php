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
];
