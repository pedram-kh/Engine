<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        // The draft-round clause shared by both review-cycle mails (AH-068).
        'round' => 'Mustand :n',
        'round_subject' => ':subject (:round)',
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator esitas mustandi ülevaatamiseks',
                'greeting' => 'Tere, :name,',
                'body' => ':creator esitas mustandi kampaaniale ":campaign". Avage kampaania ja kinnitage see, taotlege muudatusi või lükake tagasi.',
                'cta' => 'Vaata mustandit',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Teie mustand kampaaniale :campaign on kinnitatud',
                'subject_revision_requested' => 'Teie mustandi kampaaniale :campaign muudatused on taotletud',
                'subject_rejected' => 'Uuendus teie mustandi kohta kampaaniale :campaign',
                'greeting' => 'Tere, :name,',
                'body_approved' => 'Suurepärane uudis — teie mustand kampaaniale ":campaign" on kinnitatud. Nüüd saate avaldada ja saata otselingi.',
                'body_revision_requested' => 'Agentuur taotleb muudatusi teie mustandis kampaaniale ":campaign". Vaadake allpool olevat tagasisidet üle ja esitage uuesti.',
                'body_rejected' => 'Pärast ülevaatamist ei ole teie mustand kampaaniale ":campaign" vastu võetud ja ülesanne on suletud.',
                'feedback_label' => 'Tagasiside',
                'cta' => 'Vaata ülesannet',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Postituse kontrollimine kampaaniale :campaign ebaõnnestus',
                'greeting' => 'Tere, :name,',
                'body' => 'Me ei suutnud automaatselt kontrollida :creator postitust kampaaniale ":campaign". Vaadake esitatud link üle.',
                'reason_label' => 'Mis juhtus',
                'reason_not_found' => 'Postitust ei leitud esitatud lingilt.',
                'reason_mismatch' => 'Tundub, et esitatud lingil olev postitus ei kuulu looja ühendatud kontole.',
                'cta' => 'Vaata ülesannet',
            ],
        ],
        'completed_on_approval' => [
            'email' => [
                'subject' => 'Teie töö kampaania :campaign jaoks on valmis',
                'greeting' => 'Tere, :name,',
                'body' => 'Teie kavand kampaania „:campaign“ jaoks on kinnitatud. Selles kampaanias avaldab sisu agentuur, seega on teie ülesanne nüüd lõpetatud — midagi enamat teha ei ole vaja.',
                'cta' => 'Vaata ülesannet',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Teie postitus kampaaniale :campaign on vastu võetud',
                'greeting' => 'Tere, :name,',
                'body' => 'Suurepärane uudis — agentuur on teie postituse kampaaniale ":campaign" üle vaadanud ja vastu võtnud. Täiendavaid toiminguid pole vaja.',
                'cta' => 'Vaata ülesannet',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Teie postituse kampaaniale :campaign puhul on vaja tegutseda',
                'greeting' => 'Tere, :name,',
                'body_fresh' => 'Agentuur ei suutnud teie postitust kampaaniale ":campaign" kontrollida ja palub esitada uus link. Avage ülesanne ja esitage uuesti.',
                'body_in_place' => 'Agentuur ei suutnud teie postitust kampaaniale ":campaign" kontrollida ja palub esitatud linki parandada. Avage ülesanne ja uuendage seda.',
                'feedback_label' => 'Märkus agentuurilt',
                'cta' => 'Ava ülesanne',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Kampaania :campaign leping on valmis',
                'greeting' => 'Tere, :name,',
                'body' => 'Kampaania ":campaign" leping on teie ülevaatamiseks valmis. Avage ülesanne, lugege tingimused läbi ja nõustuge nendega.',
                'cta' => 'Vaata lepingut',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator nõustus lepinguga',
                'greeting' => 'Tere, :name,',
                'body' => ':creator nõustus kampaania ":campaign" lepinguga. Nad saavad nüüd alustada oma mustandi kallal töötamist.',
                'cta' => 'Vaata kampaaniat',
            ],
        ],
        'invite_received' => [
            'email' => [
                'subject_fresh' => 'Teil on uus pakkumine kampaaniale :campaign',
                'subject_re_offer' => 'Uuendatud pakkumine kampaaniale :campaign',
                'greeting' => 'Tere, :name,',
                'body_fresh' => 'Teid on kutsutud tööle kampaanial ":campaign". Avage ülesanne, et pakkumine üle vaadata ja vastata.',
                'body_re_offer' => 'Kampaanial ":campaign" ootab teid uuendatud pakkumine. Avage ülesanne, et see üle vaadata ja vastata.',
                'cta' => 'Vaata pakkumist',
            ],
        ],
    ],
    // AH-056 (Jobs Board chunk 3, D6) — the job-posted fan-out mail. Queued and
    // localized at queue time to the recipient's preferred_language, rendered
    // through the shared `catalyst` markdown theme. Carries the agency + campaign
    // names and a deep link only: the brand's identity is board content, behind
    // the visibility predicate, and an inbox is not.
    'job_posted' => [
        'subject' => ':agency avaldas uue tööpakkumise',
        'greeting' => 'Tere, :name,',
        'body' => ':agency avaldas sinu töölaual uue tööpakkumise: „:campaign“. Ava see, et näha üksikasju ja kandideerida.',
        'cta' => 'Vaata tööpakkumist',
        'ignore' => 'Saad selle kirja, sest kuulud agentuuri :agency loojate nimekirja.',
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
            'subject' => 'Uus kandideerimine tööle :campaign',
            'greeting' => 'Tere, :name,',
            'body' => ':creator kandideeris tööle „:campaign“. Avage kampaania, vaadake kandideerimine üle ja saatke pakkumine.',
            'cta' => 'Vaata kandideerimist',
        ],
        'accepted' => [
            'subject' => 'Teie kandideerimine tööle :campaign võeti vastu',
            'greeting' => 'Tere, :name,',
            'body' => ':agency võttis teie kandideerimise tööle „:campaign“ vastu ja saatis teile pakkumise. Avage ülesanne, vaadake tingimused üle ning võtke pakkumine vastu või lükake tagasi.',
            'cta' => 'Vaata pakkumist',
        ],
        'rejected' => [
            'subject' => 'Uudised teie kandideerimise kohta tööle :campaign',
            'greeting' => 'Tere, :name,',
            'body_agency_rejected' => 'Täname, et kandideerisite tööle „:campaign“. Teid ei valitud selle töö jaoks. Uued tööpakkumised ilmuvad teie tahvlile regulaarselt.',
            'body_campaign_closed' => 'Täname, et kandideerisite tööle „:campaign“. Kampaania on lõppenud, seega teie kandideerimist edasi ei menetleta. Uued tööpakkumised ilmuvad teie tahvlile regulaarselt.',
            'cta' => 'Vaata tööpakkumisi',
        ],
    ],
    // AH-069 (D6/Q4) — the refuse-flip message. Turning posting OFF stops the
    // board RENDERING its posting column, so a card sitting there would be
    // present in the database and invisible on screen. The update endpoint
    // refuses the flip and says which cards are in the way, by creator name; the
    // machine-readable count and the assignment/card ULIDs travel in the error's
    // `meta` so a client can link straight to them.
    'posting_toggle' => [
        'cards_present' => '{1} :creators kaart on endiselt avaldamise veerus. Teisaldage kaart veerust välja, enne kui lülitate loojate avaldamise välja.|[2,*] :count kaarti on endiselt avaldamise veerus (:creators). Teisaldage need veerust välja, enne kui lülitate loojate avaldamise välja.',
    ],
];
