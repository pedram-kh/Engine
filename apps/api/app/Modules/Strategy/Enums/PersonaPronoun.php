<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Enums;

/** The pronoun of a persona. It drives the card labels only ("What she tells herself"). */
enum PersonaPronoun: string
{
    case She = 'she';
    case He = 'he';
    case They = 'they';
}
