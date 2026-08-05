<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The primary verb of a cooking step. Drives the headline of the guided-cooking
 * screen ("ADD 150 g of apples", "FRY for 3 minutes").
 */
enum StepAction: string
{
    case Prepare = 'prepare';
    case Add = 'add';
    case Mix = 'mix';
    case Blend = 'blend';
    case Chop = 'chop';
    case Heat = 'heat';
    case Boil = 'boil';
    case Fry = 'fry';
    case Bake = 'bake';
    case Chill = 'chill';
    case Rest = 'rest';
    case Serve = 'serve';
}
