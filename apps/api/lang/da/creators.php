<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Du er blevet inviteret til at tilslutte dig :agency på Catalyst',
            'greeting' => 'Hej,',
            'body' => ':agency har inviteret dig til at tilslutte dig deres roster på Catalyst. Klik på knappen nedenfor for at oprette din creator-profil.',
            'cta' => 'Kom i gang',
            'expiry' => 'Denne invitation udløber den :date.',
            'ignore' => 'Hvis du ikke forventede denne invitation, kan du ignorere denne e-mail.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Din Catalyst-ansøgning er godkendt',
            'greeting' => 'Hej :name,',
            'body' => 'Gode nyheder — din creator-ansøgning er godkendt. Du har nu fuld adgang til dit Catalyst-dashboard.',
            'cta' => 'Gå til dashboard',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'En opdatering om din Catalyst-ansøgning',
            'greeting' => 'Hej :name,',
            'body' => 'Tak for din ansøgning til Catalyst. Efter gennemgang er vi desværre ikke i stand til at godkende din ansøgning på nuværende tidspunkt.',
            'reason_label' => 'Årsag',
            'resubmit_hint' => 'Du kan opdatere din ansøgning og indsende den igen til gennemgang via dit dashboard.',
            'cta' => 'Se ansøgning',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency ønsker at forbinde med dig på Catalyst',
            'greeting' => 'Hej :name,',
            'body' => ':agency ønsker at tilføje dig til deres roster på Catalyst. Åbn dit dashboard for at acceptere eller afvise anmodningen.',
            'cta' => 'Se anmodning',
            'ignore' => 'Hvis du ikke kender dette bureau, kan du blot afvise anmodningen — ingenting ændres, før du accepterer.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Færdiggør opsætningen af din Catalyst-konto',
            'greeting' => 'Hej :name,',
            'body' => 'Du er begyndt at oprette din Catalyst-skaberkonto, men har endnu ikke bekræftet din e-mailadresse. Bekræft den nu for at fortsætte, hvor du slap, og færdiggøre din registrering.',
            'cta' => 'Bekræft min e-mail',
            'expiry' => 'Dette link udløber om :hours timer. Hvis det udløber, kan du anmode om et nyt fra login-siden.',
            'ignore' => 'Hvis du ikke har startet denne registrering, kan du roligt ignorere denne e-mail.',
        ],
        'finish' => [
            'subject' => 'Færdiggør opsætningen af din Catalyst-skaberprofil',
            'greeting' => 'Hej :name,',
            'body' => 'Du er begyndt at opsætte din Catalyst-skaberprofil, men har endnu ikke gjort den færdig. Fortsæt, hvor du slap, for at færdiggøre din registrering.',
            'cta' => 'Færdiggør min profil',
            'ignore' => 'Hvis du allerede har færdiggjort din profil, kan du roligt ignorere denne e-mail.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency har opdateret din samarbejdsstatus på Catalyst',
            'greeting' => 'Hej :name,',
            'body' => ':agency har opdateret din samarbejdsstatus på Catalyst. Hvis du har spørgsmål, bedes du kontakte dem direkte.',
            'closing' => 'Tak fordi du er en del af Catalyst.',
        ],
    ],
];
