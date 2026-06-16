<?php

declare(strict_types=1);

return [
    'invitations' => [
        'email' => [
            'subject' => 'Byli jste pozváni do :agency na Catalyst',
            'greeting' => 'Dobrý den,',
            'body' => ':agency vás pozvala k připojení do svého rosteru na Catalyst. Klikněte na tlačítko níže a nastavte svůj tvůrčí profil.',
            'cta' => 'Začít',
            'expiry' => 'Tato pozvánka vyprší :date.',
            'ignore' => 'Pokud jste tuto pozvánku neočekávali, můžete tento e-mail bezpečně ignorovat.',
        ],
    ],
    'approved' => [
        'email' => [
            'subject' => 'Vaše přihláška do Catalyst byla schválena',
            'greeting' => 'Dobrý den, :name,',
            'body' => 'Skvělé zprávy — vaše přihláška tvůrce byla schválena. Nyní máte plný přístup k řídicímu panelu Catalyst.',
            'cta' => 'Přejít na řídicí panel',
        ],
    ],
    'rejected' => [
        'email' => [
            'subject' => 'Aktualizace vaší přihlášky do Catalyst',
            'greeting' => 'Dobrý den, :name,',
            'body' => 'Děkujeme za přihlášku do Catalyst. Po posouzení nemůžeme vaši přihlášku v tuto chvíli schválit.',
            'reason_label' => 'Důvod',
            'resubmit_hint' => 'Přihlášku můžete aktualizovat a znovu odeslat k dalšímu posouzení z řídicího panelu.',
            'cta' => 'Zkontrolovat přihlášku',
        ],
    ],
    'connection_request' => [
        'email' => [
            'subject' => ':agency se s vámi chce propojit na Catalyst',
            'greeting' => 'Dobrý den, :name,',
            'body' => ':agency by vás ráda přidala do svého rosteru na Catalyst. Otevřete řídicí panel a přijměte nebo odmítněte žádost.',
            'cta' => 'Zobrazit žádost',
            'ignore' => 'Pokud tuto agenturu neznáte, můžete ji jednoduše odmítnout — nic se nezmění, dokud nepřijmete.',
        ],
    ],
    'blacklisted' => [
        'email' => [
            'subject' => ':agency aktualizovala váš stav spolupráce na Catalyst',
            'greeting' => 'Dobrý den, :name,',
            'body' => ':agency aktualizovala váš stav spolupráce na Catalyst. Máte-li jakékoli dotazy, kontaktujte je přímo.',
            'closing' => 'Děkujeme, že jste součástí Catalyst.',
        ],
    ],
];
