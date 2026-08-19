<?php

declare(strict_types=1);

return [
    'system' => [
        'assignment' => [
            'contracted' => 'Il contratto è stato firmato — la produzione può iniziare.',
            'contracted_without_contract' => 'La produzione può iniziare.',
            'draft_submitted' => 'Una bozza è stata inviata per la revisione.',
            'draft_approved' => 'La bozza è stata approvata.',
            'revision_requested' => 'Sono state richieste revisioni sulla bozza.',
            'draft_rejected' => 'La bozza è stata rifiutata.',
            'posted_by_creator' => 'Il creator ha contrassegnato il contenuto come pubblicato.',
            'live_verified' => 'Il post dal vivo è stato verificato.',
            'manually_verified' => 'Il post è stato verificato manualmente.',
            'resubmit_requested' => 'È stato richiesto un nuovo invio.',
            'payment_released' => 'Il pagamento è stato rilasciato.',
        ],
    ],

    'digest' => [
        'subject' => 'Hai messaggi non letti',
        'greeting' => 'Ciao :name,',
        'intro' => 'Hai :count messaggio/i non letto/i in :threads conversazione/i.',
        'cta' => 'Apri i tuoi messaggi',
        'thread_line' => ':campaign con :counterparty — :count non letti',
        'unknown_campaign' => 'una campagna',
        'unknown_counterparty' => 'qualcuno',
    ],

    'new_message' => [
        'subject_campaign' => 'Nuovo messaggio su :counterparty',
        'subject_relationship' => 'Nuovo messaggio da :counterparty',
        'greeting' => 'Ciao :name,',
        'body_campaign' => ':sender ti ha inviato un nuovo messaggio su ":counterparty".',
        'body_relationship' => ':sender di :counterparty ti ha inviato un nuovo messaggio.',
        'cta' => 'Apri la conversazione',
    ],
];
