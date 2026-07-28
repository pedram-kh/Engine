<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        'draft_submitted' => [
            'email' => [
                'subject' => 'Ο/η :creator υπέβαλε πρόχειρο για επανεξέταση',
                'greeting' => 'Γεια σας, :name,',
                'body' => 'Ο/η :creator υπέβαλε πρόχειρο για την καμπάνια ":campaign". Ανοίξτε την καμπάνια και εγκρίνετε, ζητήστε αλλαγές ή απορρίψτε το.',
                'cta' => 'Επανεξέταση προχείρου',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Το πρόχειρό σας για την καμπάνια :campaign εγκρίθηκε',
                'subject_revision_requested' => 'Ζητήθηκαν αλλαγές στο πρόχειρό σας για την καμπάνια :campaign',
                'subject_rejected' => 'Ενημέρωση για το πρόχειρό σας για την καμπάνια :campaign',
                'greeting' => 'Γεια σας, :name,',
                'body_approved' => 'Υπέροχα νέα — το πρόχειρό σας για την καμπάνια ":campaign" εγκρίθηκε. Μπορείτε τώρα να δημοσιεύσετε και να στείλετε τον σύνδεσμο.',
                'body_revision_requested' => 'Το γραφείο ζητά αλλαγές στο πρόχειρό σας για την καμπάνια ":campaign". Ελέγξτε τα σχόλια παρακάτω και υποβάλετε εκ νέου.',
                'body_rejected' => 'Μετά την επανεξέταση, το πρόχειρό σας για την καμπάνια ":campaign" δεν έγινε δεκτό και η εργασία έκλεισε.',
                'feedback_label' => 'Σχόλια',
                'cta' => 'Προβολή εργασίας',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Η επαλήθευση δημοσίευσης για την καμπάνια :campaign απέτυχε',
                'greeting' => 'Γεια σας, :name,',
                'body' => 'Δεν μπορέσαμε να επαληθεύσουμε αυτόματα τη δημοσίευση του/της :creator για την καμπάνια ":campaign". Ελέγξτε τον υποβληθέντα σύνδεσμο.',
                'reason_label' => 'Τι συνέβη',
                'reason_not_found' => 'Η δημοσίευση δεν βρέθηκε στον υποβληθέντα σύνδεσμο.',
                'reason_mismatch' => 'Η δημοσίευση στον υποβληθέντα σύνδεσμο φαίνεται να μην ανήκει στον συνδεδεμένο λογαριασμό του δημιουργού.',
                'cta' => 'Επανεξέταση εργασίας',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Η δημοσίευσή σας για την καμπάνια :campaign έγινε δεκτή',
                'greeting' => 'Γεια σας, :name,',
                'body' => 'Υπέροχα νέα — το γραφείο επανεξέτασε και αποδέχτηκε τη δημοσίευσή σας για την καμπάνια ":campaign". Δεν απαιτούνται περαιτέρω ενέργειες.',
                'cta' => 'Προβολή εργασίας',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Απαιτείται ενέργεια για τη δημοσίευσή σας για την καμπάνια :campaign',
                'greeting' => 'Γεια σας, :name,',
                'body_fresh' => 'Το γραφείο δεν μπόρεσε να επαληθεύσει τη δημοσίευσή σας για την καμπάνια ":campaign" και ζητά να υποβάλετε νέο σύνδεσμο. Ανοίξτε την εργασία και υποβάλετε εκ νέου.',
                'body_in_place' => 'Το γραφείο δεν μπόρεσε να επαληθεύσει τη δημοσίευσή σας για την καμπάνια ":campaign" και ζητά να διορθώσετε τον υποβληθέντα σύνδεσμο. Ανοίξτε την εργασία και ενημερώστε τον.',
                'feedback_label' => 'Σημείωση από το γραφείο',
                'cta' => 'Άνοιγμα εργασίας',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Η σύμβαση για την καμπάνια :campaign είναι έτοιμη',
                'greeting' => 'Γεια σας, :name,',
                'body' => 'Η σύμβαση για την καμπάνια ":campaign" είναι έτοιμη για επανεξέταση. Ανοίξτε την εργασία, διαβάστε τους όρους και αποδεχτείτε τους.',
                'cta' => 'Επανεξέταση σύμβασης',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => 'Ο/η :creator αποδέχτηκε τη σύμβαση',
                'greeting' => 'Γεια σας, :name,',
                'body' => 'Ο/η :creator αποδέχτηκε τη σύμβαση για την καμπάνια ":campaign". Μπορούν τώρα να ξεκινήσουν να εργάζονται στο πρόχειρό τους.',
                'cta' => 'Προβολή καμπάνιας',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => 'Η :agency δημοσίευσε μια νέα αγγελία',
        'greeting' => 'Γεια σου :name,',
        'body' => 'Η :agency δημοσίευσε μια νέα αγγελία στον πίνακά σου: «:campaign». Άνοιξέ την για να δεις τις λεπτομέρειες και να κάνεις αίτηση.',
        'cta' => 'Δες την αγγελία',
        'ignore' => 'Λαμβάνεις αυτό το μήνυμα επειδή βρίσκεσαι στη λίστα δημιουργών της :agency.',
    ],
    // AH-058 (Jobs Board chunk 4, D6) — the three application mails. All three
    // are queued and localized at queue time to the recipient's
    // preferred_language (a worker has no request locale), and all three are
    // gated by the `application_notifications_enabled` Pennant flag on the MAIL
    // leg only — the in-app rows write regardless.
    //
    // `rejected` carries TWO body variants selected by
    // ApplicationRejectionCause (`body_agency_rejected` / `body_campaign_closed`)
    // under ONE subject, the draft-reviewed `body_ . $outcome` precedent: the
    // recipient's question is the same either way, and two mailables would double
    // 24 locales of copy to express one sentence of difference.
    //
    // ⚠ No agency-supplied reason exists anywhere in the reject copy, by design
    // (D4): none is collected or stored, and the audit row plus its actor is the
    // internal record.
    'campaign_application' => [
        'submitted' => [
            'subject' => 'Νέα αίτηση για :campaign',
            'greeting' => 'Γεια σου :name,',
            'body' => 'Ο/Η :creator υπέβαλε αίτηση για «:campaign». Ανοίξτε την καμπάνια για να δείτε την αίτηση και να στείλετε προσφορά.',
            'cta' => 'Προβολή αίτησης',
        ],
        'accepted' => [
            'subject' => 'Η αίτησή σας για :campaign εγκρίθηκε',
            'greeting' => 'Γεια σου :name,',
            'body' => 'Η :agency αποδέχτηκε την αίτησή σας για «:campaign» και σας έστειλε προσφορά. Ανοίξτε την ανάθεση για να δείτε τους όρους και να την αποδεχτείτε ή να την απορρίψετε.',
            'cta' => 'Προβολή προσφοράς',
        ],
        'rejected' => [
            'subject' => 'Ενημέρωση για την αίτησή σας για :campaign',
            'greeting' => 'Γεια σου :name,',
            'body_agency_rejected' => 'Σας ευχαριστούμε για την αίτησή σας για «:campaign». Δεν επιλεχθήκατε για αυτήν την εργασία. Νέες αγγελίες δημοσιεύονται τακτικά στον πίνακά σας.',
            'body_campaign_closed' => 'Σας ευχαριστούμε για την αίτησή σας για «:campaign». Η καμπάνια έκλεισε, οπότε η αίτησή σας δεν θα προχωρήσει. Νέες αγγελίες δημοσιεύονται τακτικά στον πίνακά σας.',
            'cta' => 'Προβολή αγγελιών',
        ],
    ],
];
