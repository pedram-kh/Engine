<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Lösenordet måste vara en textsträng.',
        'too_short' => 'Lösenordet måste vara minst :min tecken.',
        'too_long' => 'Lösenordet får inte överstiga :max tecken.',
        'breached' => 'Det här lösenordet finns i kända dataintrång och kan inte användas. Välj ett annat.',
    ],

    'login' => [
        'invalid_credentials' => 'Ogiltig e-post eller lösenord.',
        'mfa_required' => 'Tvåfaktorsautentisering krävs för att slutföra inloggningen.',
        'account_locked_temporary' => 'För många misslyckade inloggningsförsök. Försök igen om :minutes minuter.',
        'account_locked' => 'Det här kontot har låsts. Återställ ditt lösenord eller kontakta support för att återfå åtkomst.',
        'rate_limited' => 'För många förfrågningar. Försök igen om :seconds sekunder.',
        'wrong_spa' => 'Det här kontot är inte registrerat för den här webbplatsen. Logga in på rätt webbplats.',
    ],

    'reset' => [
        'subject' => 'Återställ ditt :app-lösenord',
        'greeting' => 'Hej :name,',
        'body' => 'Vi fick en begäran om att återställa lösenordet på ditt :app-konto. Länken nedan är giltig i :minutes minuter.',
        'cta' => 'Återställ lösenord',
        'ignore' => 'Om du inte begärde detta kan du ignorera det här e-postmeddelandet — ditt lösenord ändras inte.',
        'invalid_token' => 'Den här länken för lösenordsåterställning är ogiltig eller har gått ut. Begär en ny.',
        'completed' => 'Ditt lösenord har återställts. Alla andra aktiva sessioner har loggats ut.',
    ],

    'email_verification' => [
        'subject' => 'Verifiera din :app-e-postadress',
        'greeting' => 'Välkommen till :app, :name!',
        'body' => 'Bekräfta din e-postadress för att slutföra konfigurationen av ditt :app-konto. Länken nedan är giltig i :hours timmar.',
        'cta' => 'Verifiera e-postadress',
        'ignore' => 'Om du inte skapade ett :app-konto kan du ignorera det här e-postmeddelandet.',
        'verification_invalid' => 'Den här verifieringslänken är ogiltig. Begär en ny.',
        'verification_expired' => 'Den här verifieringslänken har gått ut. Begär en ny.',
        'already_verified' => 'Den här e-postadressen har redan verifierats.',
    ],

    'signup' => [
        'email_taken' => 'Ett konto med den här e-postadressen finns redan.',
    ],

    'mfa' => [
        'invalid_code' => 'Tvåfaktorkoden är ogiltig. Försök igen.',
        'rate_limited' => 'För många ogiltiga tvåfaktorförsök. Försök igen om :minutes minuter.',
        'enrollment_suspended' => 'Tvåfaktorsautentisering har inaktiverats för det här kontot. Kontakta support för att återställa åtkomsten.',
        'enrollment_required' => 'Tvåfaktorsautentisering måste aktiveras innan du kan fortsätta.',
        'already_enabled' => 'Tvåfaktorsautentisering är redan aktiverat för det här kontot.',
        'not_enabled' => 'Tvåfaktorsautentisering är inte aktiverat för det här kontot.',
        'provisional_expired' => 'Tvåfaktorregistreringssessionen har gått ut. Börja om.',
    ],
];
