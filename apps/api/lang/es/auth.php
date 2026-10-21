<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'La contraseña debe ser una cadena de texto.',
        'too_short' => 'La contraseña debe tener al menos :min caracteres.',
        'too_long' => 'La contraseña no puede superar los :max caracteres.',
        'breached' => 'Esta contraseña aparece en filtraciones de datos conocidas y no se puede utilizar. Elige otra distinta.',
    ],

    'login' => [
        'invalid_credentials' => 'Correo electrónico o contraseña no válidos.',
        'mfa_required' => 'Se requiere autenticación multifactor para completar el inicio de sesión.',
        'account_locked_temporary' => 'Demasiados intentos de inicio de sesión fallidos. Inténtalo de nuevo en :minutes minutos.',
        'account_locked' => 'Esta cuenta ha sido bloqueada. Restablece tu contraseña o contacta con soporte para recuperar el acceso.',
        'rate_limited' => 'Demasiadas solicitudes. Inténtalo de nuevo en :seconds segundos.',
        'wrong_spa' => 'Esta cuenta no está registrada en este sitio. Inicia sesión en el sitio correcto.',
    ],

    'reset' => [
        'subject' => 'Restablece tu contraseña de :app',
        'greeting' => 'Hola :name:',
        'body' => 'Hemos recibido una solicitud para restablecer la contraseña de tu cuenta de :app. El enlace de abajo es válido durante :minutes minutos.',
        'cta' => 'Restablecer contraseña',
        'ignore' => 'Si no has solicitado esto, puedes ignorar este correo con total tranquilidad: tu contraseña no cambiará.',
        'invalid_token' => 'Este enlace para restablecer la contraseña no es válido o ha caducado. Solicita uno nuevo.',
        'completed' => 'Tu contraseña se ha restablecido. Se ha cerrado el resto de sesiones activas.',
    ],

    'email_verification' => [
        'subject' => 'Verifica tu dirección de correo de :app',
        'greeting' => '¡Te damos la bienvenida a :app, :name!',
        'body' => 'Confirma tu dirección de correo electrónico para terminar de configurar tu cuenta de :app. El enlace de abajo es válido durante :hours horas.',
        'cta' => 'Verificar dirección de correo',
        'ignore' => 'Si no has creado una cuenta de :app, puedes ignorar este correo con total tranquilidad.',
        'verification_invalid' => 'Este enlace de verificación no es válido. Solicita uno nuevo.',
        'verification_expired' => 'Este enlace de verificación ha caducado. Solicita uno nuevo.',
        'already_verified' => 'Esta dirección de correo electrónico ya se ha verificado.',
    ],

    'signup' => [
        'email_taken' => 'Ya existe una cuenta con esta dirección de correo electrónico. Inicia sesión para usarla o restablece tu contraseña. Si pediste que se eliminara esta cuenta, al iniciar sesión podrás restaurarla.',
    ],

    'mfa' => [
        'invalid_code' => 'El código de doble factor no es válido. Inténtalo de nuevo.',
        'rate_limited' => 'Demasiados intentos de doble factor no válidos. Inténtalo de nuevo en :minutes minutos.',
        'enrollment_suspended' => 'La autenticación de doble factor se ha suspendido en esta cuenta. Contacta con soporte para restaurar el acceso.',
        'enrollment_required' => 'Debes activar la autenticación de doble factor antes de continuar.',
        'already_enabled' => 'La autenticación de doble factor ya está activada en esta cuenta.',
        'not_enabled' => 'La autenticación de doble factor no está activada en esta cuenta.',
        'provisional_expired' => 'La sesión de configuración del doble factor ha caducado. Vuelve a empezar.',
    ],

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'Se ha restablecido tu verificación en dos pasos',
            'greeting' => 'Hola :name:',
            'body' => 'Un administrador de la plataforma ha restablecido la verificación en dos pasos de tu cuenta de :app. Tu aplicación de autenticación anterior y tus códigos de recuperación ya no funcionan.',
            'next' => 'Se te pedirá que vuelvas a configurar la verificación en dos pasos la próxima vez que accedas a una parte protegida de la aplicación.',
            'unexpected' => 'Si no has solicitado esto, contacta con el soporte de inmediato.',
            'cta' => 'Ir a :app',
        ],
        'exempted' => [
            'subject' => 'La verificación en dos pasos ya no es obligatoria en tu cuenta',
            'greeting' => 'Hola :name:',
            'body' => 'Un administrador de la plataforma ha eliminado la obligación de verificación en dos pasos de tu cuenta de :app. Ahora puedes usar sin configurarla las áreas que antes te la pedían.',
            'still_enrolled' => 'Si ya tienes la verificación en dos pasos configurada, sigue activa y se te pedirá un código al iniciar sesión: puedes mantenerla o desactivarla en tus ajustes.',
            'unexpected' => 'Si no esperabas este cambio, contacta con el soporte.',
        ],
        'exemption_removed' => [
            'subject' => 'La verificación en dos pasos vuelve a ser obligatoria en tu cuenta',
            'greeting' => 'Hola :name:',
            'body' => 'Un administrador de la plataforma ha restaurado la obligación de verificación en dos pasos en tu cuenta de :app.',
            'next' => 'La próxima vez que accedas a un área protegida se te pedirá configurar la verificación en dos pasos, si aún no lo has hecho.',
            'unexpected' => 'Si no esperabas este cambio, contacta con el soporte.',
        ],
    ],
];
