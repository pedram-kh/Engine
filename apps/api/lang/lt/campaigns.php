<?php

declare(strict_types=1);

return [
    'assignment_notifications' => [
        'draft_submitted' => [
            'email' => [
                'subject' => ':creator pateikė juodraštį peržiūrai',
                'greeting' => 'Sveiki, :name,',
                'body' => ':creator pateikė juodraštį kampanijai ":campaign". Atidarykite kampaniją ir patvirtinkite, paprašykite pakeitimų arba atmeskite.',
                'cta' => 'Peržiūrėti juodraštį',
            ],
        ],
        'reviewed' => [
            'email' => [
                'subject_approved' => 'Jūsų juodraštis kampanijai :campaign patvirtintas',
                'subject_revision_requested' => 'Paprašyta pakeitimų jūsų juodraštyje kampanijai :campaign',
                'subject_rejected' => 'Atnaujinimas apie jūsų juodraštį kampanijai :campaign',
                'greeting' => 'Sveiki, :name,',
                'body_approved' => 'Puikios žinios — jūsų juodraštis kampanijai ":campaign" patvirtintas. Dabar galite skelbti ir siųsti tiesioginę nuorodą.',
                'body_revision_requested' => 'Agentūra prašo pakeitimų jūsų juodraštyje kampanijai ":campaign". Peržiūrėkite žemiau pateiktą atsiliepimą ir pateikite iš naujo.',
                'body_rejected' => 'Po peržiūros jūsų juodraštis kampanijai ":campaign" nepriimtas ir užduotis uždaryta.',
                'feedback_label' => 'Atsiliepimai',
                'cta' => 'Žiūrėti užduotį',
            ],
        ],
        'verification_failed' => [
            'email' => [
                'subject' => 'Įrašo patikrinimas kampanijai :campaign nepavyko',
                'greeting' => 'Sveiki, :name,',
                'body' => 'Nepavyko automatiškai patikrinti :creator įrašo kampanijoje ":campaign". Peržiūrėkite pateiktą nuorodą.',
                'reason_label' => 'Kas atsitiko',
                'reason_not_found' => 'Įrašas nerastas pateiktoje nuorodoje.',
                'reason_mismatch' => 'Atrodo, kad įrašas pateiktoje nuorodoje nepriklauso susijusiai kūrėjo paskyrai.',
                'cta' => 'Peržiūrėti užduotį',
            ],
        ],
        'manually_verified' => [
            'email' => [
                'subject' => 'Jūsų įrašas kampanijai :campaign priimtas',
                'greeting' => 'Sveiki, :name,',
                'body' => 'Puikios žinios — agentūra peržiūrėjo ir priėmė jūsų įrašą kampanijai ":campaign". Tolesnių veiksmų nereikia.',
                'cta' => 'Žiūrėti užduotį',
            ],
        ],
        'resubmit_requested' => [
            'email' => [
                'subject' => 'Reikalingas veiksmas dėl jūsų įrašo kampanijai :campaign',
                'greeting' => 'Sveiki, :name,',
                'body_fresh' => 'Agentūra negalėjo patikrinti jūsų įrašo kampanijai ":campaign" ir prašo pateikti naują nuorodą. Atidarykite užduotį ir pateikite iš naujo.',
                'body_in_place' => 'Agentūra negalėjo patikrinti jūsų įrašo kampanijai ":campaign" ir prašo pataisyti pateiktą nuorodą. Atidarykite užduotį ir atnaujinkite ją.',
                'feedback_label' => 'Pastaba iš agentūros',
                'cta' => 'Atidaryti užduotį',
            ],
        ],
        'contract_attached' => [
            'email' => [
                'subject' => 'Sutartis kampanijai :campaign parengta',
                'greeting' => 'Sveiki, :name,',
                'body' => 'Sutartis kampanijai ":campaign" parengta jūsų peržiūrai. Atidarykite užduotį, perskaitykite sąlygas ir priimkite jas.',
                'cta' => 'Peržiūrėti sutartį',
            ],
        ],
        'contract_accepted' => [
            'email' => [
                'subject' => ':creator priėmė sutartį',
                'greeting' => 'Sveiki, :name,',
                'body' => ':creator priėmė sutartį kampanijai ":campaign". Jie dabar gali pradėti dirbti su savo juodraščiu.',
                'cta' => 'Žiūrėti kampaniją',
            ],
        ],
    ],
];
