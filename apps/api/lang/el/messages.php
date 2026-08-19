<?php

declare(strict_types=1);

return [
    'system' => [
        'assignment' => [
            'contracted' => 'Η σύμβαση υπογράφτηκε — η παραγωγή μπορεί να ξεκινήσει.',
            'contracted_without_contract' => 'Η παραγωγή μπορεί να ξεκινήσει.',
            'draft_submitted' => 'Το πρόχειρο υποβλήθηκε για επανεξέταση.',
            'draft_approved' => 'Το πρόχειρο εγκρίθηκε.',
            'revision_requested' => 'Ζητήθηκαν αναθεωρήσεις προχείρου.',
            'draft_rejected' => 'Το πρόχειρο απορρίφθηκε.',
            'posted_by_creator' => 'Ο δημιουργός επισήμανε το περιεχόμενο ως δημοσιευμένο.',
            'live_verified' => 'Η ζωντανή ανάρτηση επαληθεύτηκε.',
            'manually_verified' => 'Η ανάρτηση επαληθεύτηκε χειροκίνητα.',
            'resubmit_requested' => 'Ζητήθηκε εκ νέου υποβολή.',
            'payment_released' => 'Η πληρωμή αποδεσμεύτηκε.',
        ],
    ],
    'digest' => [
        'subject' => 'Έχετε αδιάβαστα μηνύματα',
        'greeting' => 'Γεια σας, :name,',
        'intro' => 'Έχετε :count αδιάβαστο/-α μήνυμα/-τα σε :threads συνομιλίες.',
        'cta' => 'Άνοιγμα μηνυμάτων',
        'thread_line' => ':campaign με :counterparty — :count αδιάβαστο/-α',
        'unknown_campaign' => 'καμπάνια',
        'unknown_counterparty' => 'κάποιος',
    ],

    'new_message' => [
        'subject_campaign' => 'Νέο μήνυμα σχετικά με :counterparty',
        'subject_relationship' => 'Νέο μήνυμα από :counterparty',
        'greeting' => 'Γεια σας, :name,',
        'body_campaign' => 'Ο/Η :sender σας έστειλε νέο μήνυμα σχετικά με ":counterparty".',
        'body_relationship' => 'Ο/Η :sender από :counterparty σας έστειλε νέο μήνυμα.',
        'cta' => 'Άνοιγμα συνομιλίας',
    ],
];
