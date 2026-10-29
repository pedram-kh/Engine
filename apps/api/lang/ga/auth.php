<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Caithfidh an pasfhocal a bheith ina theaghrán.',
        'too_short' => 'Caithfidh an pasfhocal a bheith ar a laghad :min carachtar.',
        'too_long' => 'Ní féidir leis an bpasfhocal a bheith níos faide ná :max carachtar.',
        'breached' => 'Tá an pasfhocal seo le feiceáil i sceitheanna sonraí aitheanta agus ní féidir é a úsáid. Roghnaigh pasfhocal eile.',
    ],
    'login' => [
        'invalid_credentials' => 'Ríomhphost nó pasfhocal neamhbhailí.',
        'mfa_required' => 'Tá fíordheimhniú dhá fhachtóir de dhíth chun an síniú isteach a chríochnú.',
        'account_locked_temporary' => 'Rómhór iarrachtaí síniú isteach míchearta. Bain triail as arís i :minutes nóiméad.',
        'account_locked' => 'Tá an cuntas seo faoi ghlas. Athshocraigh do phasfhocal nó déan teagmháil le tacaíocht.',
        'rate_limited' => 'Rómhór iarratas. Bain triail as arís i :seconds soicind.',
        'wrong_spa' => 'Níl an cuntas seo cláraithe don suíomh seo. Sínigh isteach ón suíomh ceart.',
    ],
    'reset' => [
        'subject' => 'Athshocraigh do phasfhocal :app',
        'greeting' => 'Dia duit, :name,',
        'body' => 'Fuaireamar iarraidh chun pasfhocal do chuntais :app a athshocrú. Tá an nasc thíos bailí ar feadh :minutes nóiméad.',
        'cta' => 'Athshocraigh pasfhocal',
        'ignore' => 'Mura ndearna tú é seo a iarraidh, is féidir leat an ríomhphost seo a neamhaird a dhéanamh go sábháilte — ní athróidh do phasfhocal.',
        'invalid_token' => 'Tá an nasc athshocraithe pasfhocail seo neamhbhailí nó éagtha. Iarr ceann nua.',
        'completed' => 'Athshocraíodh do phasfhocal. Síníodh amach as gach seisiún gníomhach eile.',
    ],
    'email_verification' => [
        'subject' => 'Deimhnigh do sheoladh ríomhphoist :app',
        'greeting' => 'Fáilte chuig :app, :name!',
        'body' => 'Deimhnigh do sheoladh ríomhphoist chun socrú do chuntais :app a chríochnú. Tá an nasc thíos bailí ar feadh :hours uaire.',
        'cta' => 'Deimhnigh seoladh ríomhphoist',
        'ignore' => 'Murar chruthaigh tú cuntas :app, is féidir leat an ríomhphost seo a neamhaird a dhéanamh go sábháilte.',
        'verification_invalid' => 'Tá an nasc deimhnithe seo neamhbhailí. Iarr ceann nua.',
        'verification_expired' => 'Tá an nasc deimhnithe seo éagtha. Iarr ceann nua.',
        'already_verified' => 'Deimhníodh an seoladh ríomhphoist seo cheana féin.',
    ],
    'signup' => [
        'email_taken' => 'Tá cuntas leis an seoladh ríomhphoist seo ann cheana féin. Sínigh isteach chun é a úsáid, nó athshocraigh do phasfhocal. Má d\'iarr tú go scriosfaí an cuntas seo, is féidir leat é a athchóiríú trí shíniú isteach.',
    ],
    'mfa' => [
        'invalid_code' => 'Tá cód dhá fhachtóir neamhbhailí. Bain triail as arís.',
        'rate_limited' => 'Rómhór iarrachtaí dhá fhachtóir neamhbhailí. Bain triail as arís i :minutes nóiméad.',
        'enrollment_suspended' => 'Cuireadh fíordheimhniú dhá fhachtóir ar fionraí don chuntas seo. Déan teagmháil le tacaíocht.',
        'enrollment_required' => 'Ní mór fíordheimhniú dhá fhachtóir a chumasú sula leanfar ar aghaidh.',
        'already_enabled' => 'Tá fíordheimhniú dhá fhachtóir cumasaithe cheana féin don chuntas seo.',
        'not_enabled' => 'Níl fíordheimhniú dhá fhachtóir cumasaithe don chuntas seo.',
        'provisional_expired' => 'D\'éag an seisiún clárúcháin fíordheimhnithe dhá fhachtóir. Tosaigh arís.',
    ],

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => 'Athshocraíodh do fhíordheimhniú dhá fhachtóir',
            'greeting' => 'Dia dhuit :name,',
            'body' => "D'athshocraigh riarthóir ardáin an fíordheimhniú dhá fhachtóir ar do chuntas :app. Ní oibríonn do sheanaip fhíordheimhnithe ná do chóid athshlánaithe a thuilleadh.",
            'next' => 'Iarrfar ort an fíordheimhniú dhá fhachtóir a shocrú arís an chéad uair eile a bhainfidh tú cuid chosanta den aip amach.',
            'unexpected' => 'Mura raibh sé seo iarrtha agat, déan teagmháil leis an tacaíocht láithreach.',
            'cta' => 'Téigh chuig :app',
        ],
        'exempted' => [
            'subject' => 'Níl fíordheimhniú dhá fhachtóir riachtanach ar do chuntas a thuilleadh',
            'greeting' => 'Dia dhuit :name,',
            'body' => "Bhain riarthóir ardáin an dualgas fíordheimhnithe dhá fhachtóir de do chuntas :app. Is féidir leat na codanna a d'iarr an socrú ort roimhe seo a úsáid gan é anois.",
            'still_enrolled' => 'Má tá an fíordheimhniú dhá fhachtóir socraithe agat cheana, fanann sé gníomhach agus iarrfar cód ort fós agus tú ag síniú isteach — is féidir leat é a choinneáil nó é a mhúchadh i do shocruithe.',
            'unexpected' => 'Mura raibh tú ag súil leis an athrú seo, déan teagmháil leis an tacaíocht.',
        ],
        'exemption_removed' => [
            'subject' => 'Tá fíordheimhniú dhá fhachtóir riachtanach ar do chuntas arís',
            'greeting' => 'Dia dhuit :name,',
            'body' => 'Chuir riarthóir ardáin an dualgas fíordheimhnithe dhá fhachtóir ar do chuntas :app ar ais.',
            'next' => 'An chéad uair eile a bhainfidh tú cuid chosanta amach, iarrfar ort an fíordheimhniú dhá fhachtóir a shocrú, mura bhfuil sé déanta agat cheana.',
            'unexpected' => 'Mura raibh tú ag súil leis an athrú seo, déan teagmháil leis an tacaíocht.',
        ],
    ],
    'deletion_mail' => [
        'subject' => 'Tá do chuntas :app sceidealaithe lena scriosadh',
        'greeting' => 'Dia duit, :name,',
        'body' => 'Fuaireamar do iarratas chun do chuntas :app a scriosadh. Scriosfar go buan é tar éis :date agus ní féidir é a chealú ina dhiaidh sin.',
        'keep' => 'Go dtí sin tá do chuntas faoi ghlas agus níor scriosadh aon rud. Chun é a choinneáil, sínigh isteach le do ríomhphost agus do phasfhocal roimh an dáta sin agus roghnaigh é a athchóiríú.',
        'unexpected' => 'Mura ndearna tú an t-iarratas seo, b\'fhéidir go bhfuil do phasfhocal ar eolas ag duine éigin eile. Athchóirigh an cuntas trí shíniú isteach, ansin athraigh do phasfhocal.',
        'support' => 'Ceisteanna? Scríobh chuig :support.',
    ],
];
