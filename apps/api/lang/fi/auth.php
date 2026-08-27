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

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'Kaksivaiheinen vahvistuksesi on nollattu',
            'greeting' => 'Hei :name,',
            'body' => 'Alustan ylläpitäjä nollasi kaksivaiheisen vahvistuksen :app-tililtäsi. Aiempi todennussovelluksesi ja palautuskoodisi eivät enää toimi.',
            'next' => 'Sinua pyydetään ottamaan kaksivaiheinen vahvistus uudelleen käyttöön, kun seuraavan kerran siirryt sovelluksen suojattuun osaan.',
            'unexpected' => 'Jos et pyytänyt tätä, ota välittömästi yhteyttä tukeen.',
            'cta' => 'Siirry :app-palveluun',
        ],
        'exempted' => [
            'subject' => 'Kaksivaiheista vahvistusta ei enää vaadita tililläsi',
            'greeting' => 'Hei :name,',
            'body' => 'Alustan ylläpitäjä poisti kaksivaiheisen vahvistuksen vaatimuksen :app-tililtäsi. Voit nyt käyttää ilman käyttöönottoa niitä osia, jotka aiemmin pyysivät sitä.',
            'still_enrolled' => 'Jos olet jo ottanut kaksivaiheisen vahvistuksen käyttöön, se pysyy aktiivisena ja koodia pyydetään edelleen kirjautumisen yhteydessä — voit pitää sen tai poistaa sen käytöstä asetuksissa.',
            'unexpected' => 'Jos et odottanut tätä muutosta, ota yhteyttä tukeen.',
        ],
        'exemption_removed' => [
            'subject' => 'Kaksivaiheinen vahvistus vaaditaan tililläsi jälleen',
            'greeting' => 'Hei :name,',
            'body' => 'Alustan ylläpitäjä palautti kaksivaiheisen vahvistuksen vaatimuksen :app-tilillesi.',
            'next' => 'Kun seuraavan kerran siirryt suojattuun osaan, sinua pyydetään ottamaan kaksivaiheinen vahvistus käyttöön, jos et ole vielä tehnyt sitä.',
            'unexpected' => 'Jos et odottanut tätä muutosta, ota yhteyttä tukeen.',
        ],
    ],
];
