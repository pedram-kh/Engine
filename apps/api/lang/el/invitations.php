<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Έχετε προσκληθεί να συμμετάσχετε στο :agency στο :app',
        'greeting' => 'Γεια σας, :name,',
        'body' => 'Ο/η :inviter σας προσκάλεσε να συμμετάσχετε στο :agency ως :role. Η πρόσκληση λήγει σε :days ημέρες.',
        'cta' => 'Αποδοχή πρόσκλησης',
        'ignore' => 'Αν δεν περιμένατε αυτή την πρόσκληση, μπορείτε να αγνοήσετε με ασφάλεια αυτό το email.',
    ],
    'roles' => [
        'agency_admin' => 'Διαχειριστής',
        'agency_manager' => 'Υπεύθυνος',
        'agency_staff' => 'Υπάλληλος',
    ],
];
