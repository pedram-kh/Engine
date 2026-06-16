<?php

declare(strict_types=1);

return [
    'system' => [
        'assignment' => [
            'contracted' => 'Le contrat a été signé — la production peut commencer.',
            'draft_submitted' => 'Un brouillon a été soumis pour révision.',
            'draft_approved' => 'Le brouillon a été approuvé.',
            'revision_requested' => 'Des modifications ont été demandées sur le brouillon.',
            'draft_rejected' => 'Le brouillon a été rejeté.',
            'posted_by_creator' => 'Le créateur a marqué le contenu comme publié.',
            'live_verified' => 'La publication en ligne a été vérifiée.',
            'manually_verified' => 'La publication a été vérifiée manuellement.',
            'resubmit_requested' => 'Une nouvelle soumission a été demandée.',
            'payment_released' => 'Le paiement a été versé.',
        ],
    ],

    'digest' => [
        'subject' => 'Vous avez des messages non lus',
        'greeting' => 'Bonjour :name,',
        'intro' => 'Vous avez :count message(s) non lu(s) dans :threads conversation(s).',
        'cta' => 'Ouvrir vos messages',
        'thread_line' => ':campaign avec :counterparty — :count non lu(s)',
        'unknown_campaign' => 'une campagne',
        'unknown_counterparty' => 'quelqu\'un',
    ],
];
