<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Borrador :n',
        'round_subject' => ':subject (:round)',
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator ha enviado un borrador para revisión',
                'greeting' => 'Hola :name:',
                'body' => ':creator ha enviado un borrador para ":campaign". Abre la campaña para aprobarlo, solicitar cambios o rechazarlo.',
                'cta' => 'Revisar el borrador',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Tu borrador para :campaign se ha aprobado',
                'subject_revision_requested' => 'Se han solicitado cambios en tu borrador de :campaign',
                'subject_rejected' => 'Una actualización sobre tu borrador de :campaign',
                'greeting' => 'Hola :name:',
                'body_approved' => 'Buenas noticias: tu borrador para ":campaign" se ha aprobado. Ya puedes publicarlo y enviar el enlace en directo.',
                'body_revision_requested' => 'La agencia ha solicitado cambios en tu borrador para ":campaign". Revisa los comentarios de abajo y vuelve a enviarlo.',
                'body_rejected' => 'Tras la revisión, tu borrador para ":campaign" no se ha aceptado y la asignación se ha cerrado.',
                'feedback_label' => 'Comentarios',
                'cta' => 'Ver la asignación',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'No se ha podido verificar la publicación de :campaign',
                'greeting' => 'Hola :name:',
                'body' => 'No hemos podido verificar automáticamente la publicación de :creator para ":campaign". Revisa el enlace enviado.',
                'reason_label' => 'Qué ha ocurrido',
                'reason_not_found' => 'No se ha podido encontrar la publicación en el enlace enviado.',
                'reason_mismatch' => 'La publicación del enlace enviado no parece pertenecer a la cuenta conectada del creador.',
                'cta' => 'Revisar la asignación',
            ],
        ],
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Tu trabajo para :campaign está completado',
                'greeting' => 'Hola :name:',
                'body' => 'Tu borrador para ":campaign" ha sido aprobado. En esta campaña la agencia publica el contenido, así que tu asignación ya está completada: no tienes que hacer nada más.',
                'cta' => 'Ver la asignación',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Tu publicación para :campaign se ha aceptado',
                'greeting' => 'Hola :name:',
                'body' => 'Buenas noticias: la agencia ha revisado y aceptado tu publicación para ":campaign". No es necesario hacer nada más.',
                'cta' => 'Ver la asignación',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Se requiere tu acción en tu publicación de :campaign',
                'greeting' => 'Hola :name:',
                'body_fresh' => 'La agencia no ha podido verificar tu publicación para ":campaign" y te pide que envíes un nuevo enlace de publicación. Abre la asignación para volver a enviarlo.',
                'body_in_place' => 'La agencia no ha podido verificar tu publicación para ":campaign" y te pide que corrijas el enlace enviado. Abre la asignación para actualizarlo.',
                'feedback_label' => 'Nota de la agencia',
                'cta' => 'Abrir la asignación',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Contrato listo para :campaign',
                'greeting' => 'Hola :name:',
                'body' => 'Hay un contrato para ":campaign" listo para tu revisión. Abre la asignación para leer las condiciones y aceptarlo.',
                'cta' => 'Revisar el contrato',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator ha aceptado el contrato',
                'greeting' => 'Hola :name:',
                'body' => ':creator ha aceptado el contrato para ":campaign". Ya puede empezar a trabajar en su borrador.',
                'cta' => 'Ver la campaña',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency ha publicado un nuevo trabajo',
        'greeting' => 'Hola :name,',
        'body' => ':agency ha publicado un nuevo trabajo en tu tablón: «:campaign». Ábrelo para ver los detalles y presentar tu candidatura.',
        'cta' => 'Ver el trabajo',
        'ignore' => 'Recibes este mensaje porque formas parte de la lista de creadores de :agency.',
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
            'subject' => 'Nueva solicitud para :campaign',
            'greeting' => 'Hola :name,',
            'body' => ':creator se ha postulado a «:campaign». Abre la campaña para revisar la solicitud y enviar una oferta.',
            'cta' => 'Revisar la solicitud',
        ],
        'accepted' => [
            'subject' => 'Tu solicitud para :campaign ha sido aceptada',
            'greeting' => 'Hola :name,',
            'body' => ':agency ha aceptado tu solicitud para «:campaign» y te ha enviado una oferta. Abre la asignación para revisar las condiciones y aceptarla o rechazarla.',
            'cta' => 'Ver la oferta',
        ],
        'rejected' => [
            'subject' => 'Novedades sobre tu solicitud para :campaign',
            'greeting' => 'Hola :name,',
            'body_agency_rejected' => 'Gracias por postularte a «:campaign». No has sido seleccionado para este trabajo. En tu tablón se publican nuevos trabajos con regularidad.',
            'body_campaign_closed' => 'Gracias por postularte a «:campaign». La campaña se ha cerrado, por lo que tu solicitud no seguirá adelante. En tu tablón se publican nuevos trabajos con regularidad.',
            'cta' => 'Ver el tablón de trabajos',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators todavía tiene una tarjeta en la columna de publicación. Saque la tarjeta de la columna antes de desactivar la publicación por creadores.|[2,*] :count tarjetas siguen en la columna de publicación (:creators). Sáquelas de la columna antes de desactivar la publicación por creadores.',
    ],
];
