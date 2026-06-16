<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator lähetti luonnoksen tarkistettavaksi',
                'greeting' => 'Hei, :name,',
                'body' => ':creator lähetti luonnoksen kampanjaan ":campaign". Avaa kampanja ja hyväksy, pyydä muutoksia tai hylkää se.',
                'cta' => 'Tarkista luonnos',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Luonnoksesi kampanjaan :campaign on hyväksytty',
                'subject_revision_requested' => 'Kampanjan :campaign luonnokseen on pyydetty muutoksia',
                'subject_rejected' => 'Päivitys luonnoksestasi kampanjaan :campaign',
                'greeting' => 'Hei, :name,',
                'body_approved' => 'Loistavia uutisia — luonnoksesi kampanjaan ":campaign" on hyväksytty. Voit nyt julkaista ja lähettää linkin.',
                'body_revision_requested' => 'Toimisto pyytää muutoksia luonnokseesi kampanjaan ":campaign". Katso alla oleva palaute ja lähetä uudelleen.',
                'body_rejected' => 'Tarkistuksen jälkeen luonnoksesi kampanjaan ":campaign" ei ole hyväksytty ja tehtävä on suljettu.',
                'feedback_label' => 'Palaute',
                'cta' => 'Katso tehtävä',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Julkaisun vahvistus kampanjaan :campaign epäonnistui',
                'greeting' => 'Hei, :name,',
                'body' => 'Emme pystyneet automaattisesti vahvistamaan :creator-julkaisua kampanjaan ":campaign". Tarkista lähetetty linkki.',
                'reason_label' => 'Mitä tapahtui',
                'reason_not_found' => 'Julkaisua ei löydy lähetetystä linkistä.',
                'reason_mismatch' => 'Lähetetyn linkin julkaisu ei näytä kuuluvan luojan yhdistettyyn tiliin.',
                'cta' => 'Tarkista tehtävä',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Julkaisusi kampanjaan :campaign on hyväksytty',
                'greeting' => 'Hei, :name,',
                'body' => 'Loistavia uutisia — toimisto on tarkistanut ja hyväksynyt julkaisusi kampanjaan ":campaign". Lisätoimia ei tarvita.',
                'cta' => 'Katso tehtävä',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Toimia tarvitaan julkaisullesi kampanjaan :campaign',
                'greeting' => 'Hei, :name,',
                'body_fresh' => 'Toimisto ei pystynyt vahvistamaan julkaisuasi kampanjaan ":campaign" ja pyytää lähettämään uuden linkin. Avaa tehtävä ja lähetä uudelleen.',
                'body_in_place' => 'Toimisto ei pystynyt vahvistamaan julkaisuasi kampanjaan ":campaign" ja pyytää korjaamaan lähetetyn linkin. Avaa tehtävä ja päivitä se.',
                'feedback_label' => 'Huomio toimistolta',
                'cta' => 'Avaa tehtävä',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Sopimus kampanjaan :campaign on valmis',
                'greeting' => 'Hei, :name,',
                'body' => 'Sopimus kampanjaan ":campaign" on valmis tarkistettavaksesi. Avaa tehtävä, lue ehdot ja hyväksy ne.',
                'cta' => 'Tarkista sopimus',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator hyväksyi sopimuksen',
                'greeting' => 'Hei, :name,',
                'body' => ':creator hyväksyi sopimuksen kampanjaan ":campaign". He voivat nyt aloittaa luonnoksensa työstämisen.',
                'cta' => 'Katso kampanja',
            ],
        ],
    ],
];
