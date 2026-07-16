<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Te han invitado a :agency en Catalyst',
            'greeting' => 'Hola:',
            'body' => ':agency te ha invitado a unirte a su roster en Catalyst. Haz clic en el botón de abajo para configurar tu perfil de creador.',
            'cta' => 'Empezar',
            'expiry' => 'Esta invitación caduca el :date.',
            'ignore' => 'Si no esperabas esta invitación, puedes ignorar este correo con total tranquilidad.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Tu solicitud de Catalyst se ha aprobado',
            'greeting' => 'Hola :name:',
            'body' => 'Buenas noticias: tu solicitud de creador se ha aprobado. Ya tienes acceso completo a tu panel de Catalyst.',
            'cta' => 'Ir a tu panel',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Una actualización sobre tu solicitud de Catalyst',
            'greeting' => 'Hola :name:',
            'body' => 'Gracias por solicitar unirte a Catalyst. Tras la revisión, no podemos aprobar tu solicitud en este momento.',
            'reason_label' => 'Motivo',
            'resubmit_hint' => 'Puedes actualizar tu solicitud y volver a enviarla para otra revisión desde tu panel.',
            'cta' => 'Revisar tu solicitud',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency quiere conectar contigo en Catalyst',
            'greeting' => 'Hola :name:',
            'body' => ':agency quiere añadirte a su roster en Catalyst. Abre tu panel para aceptar o rechazar la solicitud.',
            'cta' => 'Ver la solicitud',
            'ignore' => 'Si no reconoces a esta agencia, simplemente puedes rechazarla: no cambia nada hasta que aceptes.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Termina de configurar tu cuenta de Catalyst',
            'greeting' => 'Hola :name,',
            'body' => 'Empezaste a crear tu cuenta de creador en Catalyst, pero aún no has confirmado tu dirección de correo electrónico. Confírmala ahora para retomar donde lo dejaste y completar tu registro.',
            'cta' => 'Confirmar mi correo',
            'expiry' => 'Este enlace caduca en :hours horas. Si caduca, puedes solicitar uno nuevo desde la página de inicio de sesión.',
            'ignore' => 'Si no iniciaste este registro, puedes ignorar este correo sin problema.',
        ],
        'finish' => [
            'subject' => 'Termina de configurar tu perfil de creador en Catalyst',
            'greeting' => 'Hola :name,',
            'body' => 'Empezaste a configurar tu perfil de creador en Catalyst, pero aún no lo has terminado. Retoma donde lo dejaste para completar tu registro.',
            'cta' => 'Completar mi perfil',
            'ignore' => 'Si ya has completado tu perfil, puedes ignorar este correo sin problema.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency ha actualizado tu estado de colaboración en Catalyst',
            'greeting' => 'Hola :name:',
            'body' => ':agency ha actualizado tu estado de colaboración en Catalyst. Si tienes alguna pregunta, contacta directamente con ellos.',
            'closing' => 'Gracias por formar parte de Catalyst.',
        ],
    ],
];
