<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Du har blivit inbjuden till :agency på Catalyst',
            'greeting' => 'Hej,',
            'body' => ':agency har bjudit in dig att gå med i deras roster på Catalyst. Klicka på knappen nedan för att konfigurera din creator-profil.',
            'cta' => 'Kom igång',
            'expiry' => 'Den här inbjudan går ut den :date.',
            'ignore' => 'Om du inte väntade dig den här inbjudan kan du ignorera det här e-postmeddelandet.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Din Catalyst-ansökan har godkänts',
            'greeting' => 'Hej :name,',
            'body' => 'Bra nyheter — din creator-ansökan har godkänts. Du har nu full tillgång till ditt Catalyst-dashboard.',
            'cta' => 'Gå till ditt dashboard',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'En uppdatering om din Catalyst-ansökan',
            'greeting' => 'Hej :name,',
            'body' => 'Tack för att du ansökte till Catalyst. Efter granskning kan vi tyvärr inte godkänna din ansökan för tillfället.',
            'reason_label' => 'Anledning',
            'resubmit_hint' => 'Du kan uppdatera din ansökan och skicka in den på nytt för en ny granskning från ditt dashboard.',
            'cta' => 'Granska din ansökan',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency vill ansluta till dig på Catalyst',
            'greeting' => 'Hej :name,',
            'body' => ':agency vill lägga till dig i sin roster på Catalyst. Öppna ditt dashboard för att acceptera eller avböja förfrågan.',
            'cta' => 'Visa förfrågan',
            'ignore' => 'Om du inte känner igen den här byrån kan du helt enkelt avböja — ingenting ändras förrän du accepterar.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency har uppdaterat din samarbetsstatus på Catalyst',
            'greeting' => 'Hej :name,',
            'body' => ':agency har uppdaterat din samarbetsstatus på Catalyst. Om du har frågor, kontakta dem direkt.',
            'closing' => 'Tack för att du är en del av Catalyst.',
        ],
    ],
];
