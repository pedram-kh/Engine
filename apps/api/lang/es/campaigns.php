<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
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
];
