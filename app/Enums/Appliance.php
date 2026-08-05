<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The tool or vessel a step happens in. The icon key is stable data rather than a
 * component name, so the guided-cooking UI can map it to whichever icon set it uses.
 */
enum Appliance: string
{
    case Pan = 'pan';
    case Pot = 'pot';
    case Oven = 'oven';
    case AirFryer = 'air_fryer';
    case Mixer = 'mixer';
    case Blender = 'blender';
    case Bowl = 'bowl';
    case BakingTin = 'baking_tin';
    case BakingTray = 'baking_tray';
    case Fridge = 'fridge';
    case Freezer = 'freezer';
    case Microwave = 'microwave';
    case Grater = 'grater';
    case Knife = 'knife';

    public function iconKey(): string
    {
        return $this->value;
    }
}
