<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Je bent uitgenodigd om :agency te joinen op Catalyst',
            'greeting' => 'Hallo,',
            'body' => ':agency heeft je uitgenodigd om deel te nemen aan hun roster op Catalyst. Klik op de knop hieronder om je creatorprofiel in te stellen.',
            'cta' => 'Aan de slag',
            'expiry' => 'Deze uitnodiging verloopt op :date.',
            'ignore' => 'Als je deze uitnodiging niet verwachtte, kun je deze e-mail negeren.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Je Catalyst-aanmelding is goedgekeurd',
            'greeting' => 'Hallo :name,',
            'body' => 'Goed nieuws — je creator-aanmelding is goedgekeurd. Je hebt nu volledige toegang tot je Catalyst-dashboard.',
            'cta' => 'Naar dashboard',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Een update over je Catalyst-aanmelding',
            'greeting' => 'Hallo :name,',
            'body' => 'Bedankt voor je aanmelding bij Catalyst. Na beoordeling kunnen we je aanmelding op dit moment helaas niet goedkeuren.',
            'reason_label' => 'Reden',
            'resubmit_hint' => 'Je kunt je aanmelding bijwerken en opnieuw indienen via je dashboard.',
            'cta' => 'Aanmelding bekijken',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency wil op Catalyst contact met je opnemen',
            'greeting' => 'Hallo :name,',
            'body' => ':agency wil je toevoegen aan hun roster op Catalyst. Open je dashboard om het verzoek te accepteren of te weigeren.',
            'cta' => 'Verzoek bekijken',
            'ignore' => 'Als je dit bureau niet kent, kun je het verzoek gewoon weigeren — er verandert niets tot je het accepteert.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency heeft je samenwerkingsstatus op Catalyst bijgewerkt',
            'greeting' => 'Hallo :name,',
            'body' => ':agency heeft je samenwerkingsstatus op Catalyst bijgewerkt. Neem bij vragen rechtstreeks contact met hen op.',
            'closing' => 'Bedankt dat je deel uitmaakt van Catalyst.',
        ],
    ],
];
