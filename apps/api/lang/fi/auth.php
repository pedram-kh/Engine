<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Salasanan on oltava merkkijono.',
        'too_short' => 'Salasanan on oltava vähintään :min merkkiä.',
        'too_long' => 'Salasana ei voi olla yli :max merkkiä.',
        'breached' => 'Tämä salasana on esiintynyt tunnetuissa tietovuodoissa eikä sitä voi käyttää. Valitse toinen salasana.',
    ],
    'login' => [
        'invalid_credentials' => 'Virheellinen sähköposti tai salasana.',
        'mfa_required' => 'Kirjautumisen viimeistelemiseen tarvitaan kaksivaiheinen todennus.',
        'account_locked_temporary' => 'Liian monta epäonnistunutta kirjautumisyritystä. Yritä uudelleen :minutes minuutin kuluttua.',
        'account_locked' => 'Tili on lukittu. Nollaa salasana tai ota yhteyttä tukeen.',
        'rate_limited' => 'Liian monta pyyntöä. Yritä uudelleen :seconds sekunnin kuluttua.',
        'wrong_spa' => 'Tätä tiliä ei ole rekisteröity tälle sivustolle. Kirjaudu oikealla sivustolla.',
    ],
    'reset' => [
        'subject' => 'Nollaa :app-salasanasi',
        'greeting' => 'Hei, :name,',
        'body' => 'Saimme pyynnön nollata :app-tilisi salasana. Alla oleva linkki on voimassa :minutes minuuttia.',
        'cta' => 'Nollaa salasana',
        'ignore' => 'Jos et pyytänyt tätä, voit turvallisesti jättää tämän sähköpostin huomiotta — salasanaasi ei muuteta.',
        'invalid_token' => 'Tämä salasanan nollausalinkki on virheellinen tai vanhentunut. Pyydä uusi.',
        'completed' => 'Salasanasi on nollattu. Kaikki muut aktiiviset istunnot on kirjattu ulos.',
    ],
    'email_verification' => [
        'subject' => 'Vahvista :app-sähköpostiosoitteesi',
        'greeting' => 'Tervetuloa :app-palveluun, :name!',
        'body' => 'Vahvista sähköpostiosoitteesi viimeistelläksesi :app-tilisi asetukset. Alla oleva linkki on voimassa :hours tuntia.',
        'cta' => 'Vahvista sähköpostiosoite',
        'ignore' => 'Jos et luonut :app-tiliä, voit turvallisesti jättää tämän sähköpostin huomiotta.',
        'verification_invalid' => 'Tämä vahvistuslinkki on virheellinen. Pyydä uusi.',
        'verification_expired' => 'Tämä vahvistuslinkki on vanhentunut. Pyydä uusi.',
        'already_verified' => 'Tämä sähköpostiosoite on jo vahvistettu.',
    ],
    'signup' => [
        'email_taken' => 'Tili tällä sähköpostiosoitteella on jo olemassa.',
    ],
    'mfa' => [
        'invalid_code' => 'Kaksivaiheinen koodi on virheellinen. Yritä uudelleen.',
        'rate_limited' => 'Liian monta virheellistä kaksivaiheista yritystä. Yritä uudelleen :minutes minuutin kuluttua.',
        'enrollment_suspended' => 'Kaksivaiheinen todennus on keskeytetty tälle tilille. Ota yhteyttä tukeen.',
        'enrollment_required' => 'Kaksivaiheinen todennus on otettava käyttöön ennen jatkamista.',
        'already_enabled' => 'Kaksivaiheinen todennus on jo käytössä tälle tilille.',
        'not_enabled' => 'Kaksivaiheinen todennus ei ole käytössä tälle tilille.',
        'provisional_expired' => 'Kaksivaiheisen todennuksen rekisteröinti-istunto on vanhentunut. Aloita alusta.',
    ],
];
