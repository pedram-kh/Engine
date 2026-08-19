<?php

declare(strict_types=1);

return [
    'system' => [
        'assignment' => [
            'contracted' => 'Sopimus on allekirjoitettu — tuotanto voi alkaa.',
            'contracted_without_contract' => 'Tuotanto voi alkaa.',
            'draft_submitted' => 'Luonnos on lähetetty tarkistettavaksi.',
            'draft_approved' => 'Luonnos on hyväksytty.',
            'revision_requested' => 'Luonnoksen tarkistuksia on pyydetty.',
            'draft_rejected' => 'Luonnos on hylätty.',
            'posted_by_creator' => 'Luoja on merkinnyt sisällön julkaistuksi.',
            'live_verified' => 'Live-julkaisu on vahvistettu.',
            'manually_verified' => 'Julkaisu on vahvistettu manuaalisesti.',
            'resubmit_requested' => 'Uudelleenlähetystä on pyydetty.',
            'payment_released' => 'Maksu on vapautettu.',
        ],
    ],
    'digest' => [
        'subject' => 'Sinulla on lukemattomia viestejä',
        'greeting' => 'Hei, :name,',
        'intro' => 'Sinulla on :count lukematonta viestiä :threads keskustelussa.',
        'cta' => 'Avaa viestit',
        'thread_line' => ':campaign :counterparty-osapuolen kanssa — :count lukematta',
        'unknown_campaign' => 'kampanja',
        'unknown_counterparty' => 'joku',
    ],

    'new_message' => [
        'subject_campaign' => 'Uusi viesti koskien kampanjaa :counterparty',
        'subject_relationship' => 'Uusi viesti lähettäjältä :counterparty',
        'greeting' => 'Hei, :name,',
        'body_campaign' => ':sender lähetti sinulle uuden viestin koskien kampanjaa ":counterparty".',
        'body_relationship' => ':sender yritykseltä :counterparty lähetti sinulle uuden viestin.',
        'cta' => 'Avaa keskustelu',
    ],
];
