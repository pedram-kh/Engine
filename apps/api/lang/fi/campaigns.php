<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Luonnos :n',
        'round_subject' => ':subject (:round)',
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
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Työsi kampanjaan :campaign on valmis',
                'greeting' => 'Hei, :name,',
                'body' => 'Luonnoksesi kampanjaan ":campaign" on hyväksytty. Tässä kampanjassa sisällön julkaisee toimisto, joten toimeksiantosi on nyt valmis — sinun ei tarvitse tehdä muuta.',
                'cta' => 'Katso tehtävä',
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
        'invite_received' => [
            'email' => [
                'subject_fresh' => 'Sinulle on uusi tarjous kampanjaan :campaign',
                'subject_re_offer' => 'Päivitetty tarjous kampanjaan :campaign',
                'greeting' => 'Hei, :name,',
                'body_fresh' => 'Sinut on kutsuttu työskentelemään kampanjassa ":campaign". Avaa tehtävä tarkistaaksesi tarjouksen ja vastataksesi.',
                'body_re_offer' => 'Sinua odottaa päivitetty tarjous kampanjassa ":campaign". Avaa tehtävä tarkistaaksesi sen ja vastataksesi.',
                'cta' => 'Näytä tarjous',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency julkaisi uuden työn',
        'greeting' => 'Hei :name,',
        'body' => ':agency julkaisi taulullesi uuden työn: ":campaign". Avaa se nähdäksesi tiedot ja hakeaksesi.',
        'cta' => 'Katso työ',
        'ignore' => 'Saat tämän viestin, koska olet toimiston :agency tekijälistalla.',
    ],
    // AH-058 (Jobs Board chunk 4, D6) — the three application mails. All three
    // are queued and localized at queue time to the recipient's
    // preferred_language (a worker has no request locale), and all three are
    // gated by the `application_notifications_enabled` Pennant flag on the MAIL
    // leg only — the in-app rows write regardless.
    //
    // `rejected` carries TWO body variants selected by
    // ApplicationRejectionCause (`body_agency_rejected` / `body_campaign_closed`)
    // under ONE subject, the draft-reviewed `body_ . $outcome` precedent: the
    // recipient's question is the same either way, and two mailables would double
    // 24 locales of copy to express one sentence of difference.
    //
    // ⚠ No agency-supplied reason exists anywhere in the reject copy, by design
    // (D4): none is collected or stored, and the audit row plus its actor is the
    // internal record.
    'campaign_application' => [
        'submitted' => [
            'subject' => 'Uusi hakemus työhön :campaign',
            'greeting' => 'Hei :name,',
            'body' => ':creator haki työhön ”:campaign”. Avaa kampanja, käy hakemus läpi ja lähetä tarjous.',
            'cta' => 'Katso hakemus',
        ],
        'accepted' => [
            'subject' => 'Hakemuksesi työhön :campaign hyväksyttiin',
            'greeting' => 'Hei :name,',
            'body' => ':agency hyväksyi hakemuksesi työhön ”:campaign” ja lähetti sinulle tarjouksen. Avaa toimeksianto, tarkista ehdot ja hyväksy tai hylkää tarjous.',
            'cta' => 'Katso tarjous',
        ],
        'rejected' => [
            'subject' => 'Tietoa hakemuksestasi työhön :campaign',
            'greeting' => 'Hei :name,',
            'body_agency_rejected' => 'Kiitos hakemuksestasi työhön ”:campaign”. Sinua ei valittu tähän työhön. Taulullesi julkaistaan uusia töitä säännöllisesti.',
            'body_campaign_closed' => 'Kiitos hakemuksestasi työhön ”:campaign”. Kampanja on päättynyt, joten hakemustasi ei käsitellä eteenpäin. Taulullesi julkaistaan uusia töitä säännöllisesti.',
            'cta' => 'Katso työpaikat',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators on yhä kortti julkaisusarakkeessa. Siirrä kortti pois sarakkeesta ennen kuin poistat tekijöiden julkaisun käytöstä.|[2,*] :count korttia on yhä julkaisusarakkeessa (:creators). Siirrä ne pois sarakkeesta ennen kuin poistat tekijöiden julkaisun käytöstä.',
    ],
];
