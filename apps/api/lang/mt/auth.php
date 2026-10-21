<?php

declare(strict_types=1);

return [
    'password' => [
        'invalid_type' => 'Il-password trid tkun string.',
        'too_short' => 'Il-password trid tkun mill-inqas :min karattri.',
        'too_long' => 'Il-password ma tistax tkun itwal minn :max karattri.',
        'breached' => 'Din il-password dehret f\'data breaches magħrufin u ma tistax tintuża. Agħżel password oħra.',
    ],
    'login' => [
        'invalid_credentials' => 'Email jew password invalida.',
        'mfa_required' => 'Awtentikazzjoni b\'żewġ fatturi hija meħtieġa biex titlestu l-dħul.',
        'account_locked_temporary' => 'Wisq tentattivi ta\' dħul falluti. Erġa\' pprova wara :minutes minuti.',
        'account_locked' => 'Dan il-kont huwa msakkar. Irrisettja l-password tiegħek jew ikkuntattja s-sapport.',
        'rate_limited' => 'Wisq talbiet. Erġa\' pprova wara :seconds sekondi.',
        'wrong_spa' => 'Dan il-kont mhuwiex reġistrat għal dan is-sit. Idħol mis-sit korrett.',
    ],
    'reset' => [
        'subject' => 'Irrisettja l-password tiegħek ta\' :app',
        'greeting' => 'Bonġu, :name,',
        'body' => 'Irċevejna talba biex nirrisettjaw il-password tal-kont :app tiegħek. Il-link hawn taħt huwa validu għal :minutes minuti.',
        'cta' => 'Irrisettja l-password',
        'ignore' => 'Jekk ma talbiex dan, tista\' tinjora din l-email b\'mod sigur — il-password tiegħek ma tinbidilx.',
        'invalid_token' => 'Dan il-link ta\' rrissettjar il-password huwa invalidu jew skada. Itlob ieħor.',
        'completed' => 'Il-password tiegħek ġiet irrisettjata. Il-sessjonijiet attivi kollha oħra ħarġu.',
    ],
    'email_verification' => [
        'subject' => 'Ivverifika l-indirizz tal-email tiegħek ta\' :app',
        'greeting' => 'Merħba f\' :app, :name!',
        'body' => 'Ivverifika l-indirizz tal-email tiegħek biex tlesti s-setup tal-kont :app tiegħek. Il-link hawn taħt huwa validu għal :hours sigħat.',
        'cta' => 'Ivverifika l-indirizz tal-email',
        'ignore' => 'Jekk ma ħloqtx kont :app, tista\' tinjora din l-email b\'mod sigur.',
        'verification_invalid' => 'Dan il-link ta\' verifika huwa invalidu. Itlob ieħor.',
        'verification_expired' => 'Dan il-link ta\' verifika skada. Itlob ieħor.',
        'already_verified' => 'Dan l-indirizz tal-email ġie vverifikat diġà.',
    ],
    'signup' => [
        'email_taken' => 'Diġà teżisti kont b\'dan l-indirizz tal-email. Idħol biex tużah, jew irrisettja l-password tiegħek. Jekk talabt li dan il-kont jitħassar, meta tidħol tista\' terġa\' tirrestawrah.',
    ],
    'mfa' => [
        'invalid_code' => 'Il-kodiċi ta\' żewġ fatturi huwa invalidu. Erġa\' pprova.',
        'rate_limited' => 'Wisq tentattivi invalidi ta\' żewġ fatturi. Erġa\' pprova wara :minutes minuti.',
        'enrollment_suspended' => 'L-awtentikazzjoni b\'żewġ fatturi hija sospiża għal dan il-kont. Ikkuntattja s-sapport.',
        'enrollment_required' => 'L-awtentikazzjoni b\'żewġ fatturi trid tiġi attivata qabel ma tkompli.',
        'already_enabled' => 'L-awtentikazzjoni b\'żewġ fatturi hija diġà attivata għal dan il-kont.',
        'not_enabled' => 'L-awtentikazzjoni b\'żewġ fatturi mhijiex attivata għal dan il-kont.',
        'provisional_expired' => 'Il-sessjoni ta\' reġistrazzjoni tal-awtentikazzjoni b\'żewġ fatturi skadiet. Ibda mill-ġdid.',
    ],

    // AH-087 — the three admin-initiated 2FA notices. The admin's reason is
    // never quoted and the acting admin is never named.
    'mfa_admin' => [
        'reset' => [
            'subject' => "L-awtentikazzjoni b'żewġ fatturi tiegħek ġiet irrisettjata",
            'greeting' => 'Ċaw :name,',
            'body' => "Amministratur tal-pjattaforma rrisettja l-awtentikazzjoni b'żewġ fatturi fuq il-kont tiegħek ta' :app. L-app tal-awtentikazzjoni preċedenti u l-kodiċijiet ta' rkupru tiegħek ma jaħdmux aktar.",
            'next' => "Id-darba li jmiss li tidħol f'parti protetta tal-app, jintalab li tikkonfigura l-awtentikazzjoni b'żewġ fatturi mill-ġdid.",
            'unexpected' => 'Jekk ma tlabtx dan, ikkuntattja lis-sapport immedjatament.',
            'cta' => 'Mur fuq :app',
        ],
        'exempted' => [
            'subject' => "L-awtentikazzjoni b'żewġ fatturi ma għadhiex meħtieġa fuq il-kont tiegħek",
            'greeting' => 'Ċaw :name,',
            'body' => "Amministratur tal-pjattaforma neħħa l-obbligu tal-awtentikazzjoni b'żewġ fatturi mill-kont tiegħek ta' :app. Il-partijiet li qabel talbuk tikkonfigurah tista' tużahom mingħajru.",
            'still_enrolled' => "Jekk diġà kkonfigurajt l-awtentikazzjoni b'żewġ fatturi, tibqa' attiva u xorta jintalab kodiċi meta tidħol — tista' żżommha jew titfiha mis-settings tiegħek.",
            'unexpected' => 'Jekk ma kontx qed tistenna din il-bidla, ikkuntattja lis-sapport.',
        ],
        'exemption_removed' => [
            'subject' => "L-awtentikazzjoni b'żewġ fatturi hija meħtieġa mill-ġdid fuq il-kont tiegħek",
            'greeting' => 'Ċaw :name,',
            'body' => "Amministratur tal-pjattaforma reġa' daħħal l-obbligu tal-awtentikazzjoni b'żewġ fatturi fuq il-kont tiegħek ta' :app.",
            'next' => "Id-darba li jmiss li tidħol f'parti protetta, jintalab li tikkonfigura l-awtentikazzjoni b'żewġ fatturi, jekk għadek ma għamiltx dan.",
            'unexpected' => 'Jekk ma kontx qed tistenna din il-bidla, ikkuntattja lis-sapport.',
        ],
    ],
    'deletion_mail' => [
        'subject' => 'Il-kont tiegħek :app huwa skedat biex jitħassar',
        'greeting' => 'Ħellow :name,',
        'body' => 'Irċevejna t-talba tiegħek biex titħassar il-kont :app tiegħek. Se jitħassar b\'mod permanenti fl-:date, u wara dan ma jistax jerġa\' jitreġġa\' lura.',
        'keep' => 'Sa dakinhar il-kont tiegħek huwa msakkar u ma tħassar xejn. Biex iżżommu, idħol bl-email u l-password tiegħek qabel dik id-data u agħżel li terġa\' tirrestawrah.',
        'unexpected' => 'Jekk ma talabtx int dan, jista\' jkun li xi ħadd ieħor jaf il-password tiegħek. Irrestawra l-kont billi tidħol, imbagħad biddel il-password tiegħek.',
        'support' => 'Mistoqsijiet? Ikteb lil :support.',
    ],
];
