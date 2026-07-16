<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Esate pakviesti prisijungti prie :agency Catalyst platformoje',
            'greeting' => 'Sveiki,',
            'body' => ':agency pakvietė jus prisijungti prie jų komandos Catalyst platformoje. Spustelėkite žemiau esantį mygtuką ir nustatykite savo kūrėjo profilį.',
            'cta' => 'Pradėti',
            'expiry' => 'Šis kvietimas baigia galioti :date.',
            'ignore' => 'Jei nelaukėte šio kvietimo, galite saugiai ignoruoti šį el. laišką.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Jūsų Catalyst paraiška patvirtinta',
            'greeting' => 'Sveiki, :name,',
            'body' => 'Puikios žinios — jūsų kūrėjo paraiška patvirtinta. Dabar turite visišką prieigą prie Catalyst valdymo skydelio.',
            'cta' => 'Eiti į valdymo skydelį',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Jūsų Catalyst paraiškos atnaujinimas',
            'greeting' => 'Sveiki, :name,',
            'body' => 'Dėkojame, kad pateikėte paraišką į Catalyst. Po peržiūros šiuo metu negalime patvirtinti jūsų paraiškos.',
            'reason_label' => 'Priežastis',
            'resubmit_hint' => 'Galite atnaujinti ir iš naujo pateikti paraišką tolesnei peržiūrai iš valdymo skydelio.',
            'cta' => 'Peržiūrėti paraišką',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency nori susisiekti su jumis Catalyst platformoje',
            'greeting' => 'Sveiki, :name,',
            'body' => ':agency norėtų pridėti jus prie savo komandos Catalyst platformoje. Atidarykite valdymo skydelį ir priimkite arba atmeskite užklausą.',
            'cta' => 'Žiūrėti užklausą',
            'ignore' => 'Jei nepažįstate šios agentūros, tiesiog atmeskite ją — niekas nepasikeis, kol nepriimsite.',
        ],
    ],
    'incomplete_nudge' => [
        'verify' => [
            'subject' => 'Užbaikite „Catalyst“ paskyros nustatymą',
            'greeting' => 'Sveiki, :name,',
            'body' => 'Pradėjote kurti savo „Catalyst“ kūrėjo paskyrą, bet dar nepatvirtinote savo el. pašto adreso. Patvirtinkite jį dabar, kad galėtumėte tęsti nuo ten, kur baigėte, ir užbaigti registraciją.',
            'cta' => 'Patvirtinti el. paštą',
            'expiry' => 'Ši nuoroda nustos galioti po :hours val. Jei ji nustos galioti, galite paprašyti naujos prisijungimo puslapyje.',
            'ignore' => 'Jei šios registracijos pradėjote ne jūs, galite nepaisyti šio el. laiško.',
        ],
        'finish' => [
            'subject' => 'Užbaikite „Catalyst“ kūrėjo profilio nustatymą',
            'greeting' => 'Sveiki, :name,',
            'body' => 'Pradėjote nustatyti savo „Catalyst“ kūrėjo profilį, bet dar jo nebaigėte. Tęskite nuo ten, kur baigėte, kad užbaigtumėte registraciją.',
            'cta' => 'Užbaigti profilį',
            'ignore' => 'Jei savo profilį jau užbaigėte, galite nepaisyti šio el. laiško.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency atnaujino jūsų bendradarbiavimo statusą Catalyst platformoje',
            'greeting' => 'Sveiki, :name,',
            'body' => ':agency atnaujino jūsų bendradarbiavimo statusą Catalyst platformoje. Jei turite klausimų, susisiekite su jais tiesiogiai.',
            'closing' => 'Dėkojame, kad esate Catalyst dalis.',
        ],
    ],
];
