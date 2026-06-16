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
        'email_taken' => 'Tá cuntas leis an seoladh ríomhphoist seo ann cheana féin.',
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
];
