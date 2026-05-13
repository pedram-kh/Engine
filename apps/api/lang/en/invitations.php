<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'You\'ve been invited to join :agency on :app',
        'greeting' => 'Hi :name,',
        'body' => ':inviter has invited you to join :agency as :role. This invitation expires in :days days.',
        'cta' => 'Accept Invitation',
        'ignore' => 'If you did not expect this invitation, you can safely ignore this email.',
    ],
    'roles' => [
        'agency_admin' => 'Admin',
        'agency_manager' => 'Manager',
        'agency_staff' => 'Staff',
    ],
];
