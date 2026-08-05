<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Catalogue\DefaultUnits;
use App\Models\Ingredient;
use Illuminate\Console\Command;

class DefaultUnitsCommand extends Command
{
    protected $signature = 'ingredients:default-units {--overwrite : Also replace units the dictionary already stated}';

    protected $description = 'Give every product the unit it is normally measured in, derived from recipe usage';

    public function handle(DefaultUnits $defaultUnits): int
    {
        $before = $this->missing();

        $this->info('Ustalam domyślne jednostki…');

        $filled = $defaultUnits->fill((bool) $this->option('overwrite'));

        $after = $this->missing();

        $this->newLine();
        $this->table(
            ['', 'Produktów'],
            [
                ['Bez jednostki przed', $before],
                ['Uzupełnione', $filled],
                ['Bez jednostki po', $after],
            ],
        );

        if ($after > 0) {
            $this->comment("{$after} produktów nie występuje w żadnym przepisie z jednostką — zostają bez domyślnej.");
        }

        return self::SUCCESS;
    }

    private function missing(): int
    {
        return Ingredient::query()->whereNull('default_unit_id')->count();
    }
}
