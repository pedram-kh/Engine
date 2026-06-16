<?php

declare(strict_types=1);

return [
    'email' => [
        'subject' => 'Sinut on kutsuttu liittymään :agency-ryhmään :app-palvelussa',
        'greeting' => 'Hei, :name,',
        'body' => ':inviter kutsui sinut liittymään :agency-ryhmään roolissa :role. Tämä kutsu vanhenee :days päivän kuluttua.',
        'cta' => 'Hyväksy kutsu',
        'ignore' => 'Jos et odottanut tätä kutsua, voit turvallisesti jättää tämän sähköpostin huomiotta.',
    ],
    'roles' => [
        'agency_admin' => 'Ylläpitäjä',
        'agency_manager' => 'Esimies',
        'agency_staff' => 'Työntekijä',
    ],
];
