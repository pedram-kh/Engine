<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
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
    ],
];
