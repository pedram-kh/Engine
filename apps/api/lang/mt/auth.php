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
        'email_taken' => 'Kont b\'dan l-indirizz tal-email diġà jeżisti.',
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
];
